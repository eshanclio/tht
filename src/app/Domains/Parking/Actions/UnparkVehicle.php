<?php

namespace App\Domains\Parking\Actions;

use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Data\UnparkVehicleData;
use App\Domains\Parking\Exceptions\VehicleNotParkedException;
use App\Domains\Parking\Models\ParkingSession;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\Vehicle;
use App\Domains\Parking\Models\VanWindow;
use App\Domains\Parking\Services\SpotAllocator;
use Illuminate\Support\Facades\DB;

final class UnparkVehicle
{
    public function __construct(
        private readonly SpotAllocator $spotAllocator,
    ) {}

    public function handle(UnparkVehicleData $data): void
    {
        // attempts: 3 — same deadlock-retry reason as ParkVehicle.
        DB::transaction(function () use ($data): void {
            $vehicle = Vehicle::query()
                ->where('parking_lot_id', $data->parkingLotId)
                ->where('license_plate', $data->licensePlate)
                ->first();

            if (! $vehicle) {
                throw new VehicleNotParkedException;
            }

            $parking = ParkingSession::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('parking_lot_id', $data->parkingLotId)
                ->active()
                ->lockForUpdate()
                ->first();

            if (! $parking) {
                throw new VehicleNotParkedException;
            }

            $parking->update(['ended_at' => now()]);

            // Capture freed spots BEFORE clearing parking_id (we need their
            // coordinates for the blocked_count decrement).
            $spots = Spot::where('parking_id', $parking->id)
                ->get(['id', 'type', 'section_id', 'grid_row', 'grid_column']);

            // For vans, also find the released window (0 or 1 row by the
            // van_windows_active_idx partial unique index).
            $releasedWindow = VanWindow::where('parking_id', $parking->id)->first();

            Spot::where('parking_id', $parking->id)->update(['parking_id' => null]);

            if ($releasedWindow !== null) {
                $releasedWindow->update(['parking_id' => null]);
            }

            foreach ($spots as $spot) {
                if ($spot->type !== SpotType::Car) {
                    continue;
                }
                $this->spotAllocator->decrementBlockedCountForCarSpot(
                    sectionId: $spot->section_id,
                    gridRow: $spot->grid_row,
                    gridColumn: $spot->grid_column,
                    excludeWindowId: $releasedWindow?->id,
                );
            }
        }, attempts: 3);
    }
}
