<?php

namespace App\Domains\Parking\Actions;

use App\Domains\Parking\Data\AllocationResult;
use App\Domains\Parking\Data\ParkVehicleData;
use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Data\VehicleType;
use App\Domains\Parking\Exceptions\VehicleAlreadyParkedException;
use App\Domains\Parking\Exceptions\VehicleTypeMismatchException;
use App\Domains\Parking\Models\ParkingSession;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\Vehicle;
use App\Domains\Parking\Models\VanWindow;
use App\Domains\Parking\Services\SpotAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ParkVehicle
{
    public function __construct(
        private readonly SpotAllocator $spotAllocator,
    ) {}

    public function handle(ParkVehicleData $data): ParkingSession
    {
        try {
            // attempts: 3 — Laravel retries automatically on Postgres deadlock
            // SQLSTATE 40P01 / 40001 raised during the blocked_count neighbor
            // UPDATE phase. Domain exceptions propagate immediately.
            return DB::transaction(function () use ($data): ParkingSession {
                $vehicle = Vehicle::createOrFirst(
                    [
                        'parking_lot_id' => $data->parkingLotId,
                        'license_plate' => $data->licensePlate,
                    ],
                    ['type' => $data->vehicleType],
                );

                if ($vehicle->type !== $data->vehicleType) {
                    throw new VehicleTypeMismatchException(
                        $vehicle->type,
                        $data->vehicleType,
                    );
                }

                $result = $this->spotAllocator->allocate(
                    $data->parkingLotId,
                    $data->vehicleType,
                );

                // Duplicate active parkings are prevented by the
                // parkings_active_vehicle_unique partial index; a concurrent
                // INSERT throws UniqueConstraintViolationException, caught
                // outside and rethrown as VehicleAlreadyParkedException.
                $parking = ParkingSession::create([
                    'parking_lot_id' => $data->parkingLotId,
                    'vehicle_id' => $vehicle->id,
                    'started_at' => now(),
                ]);

                $this->applyAllocation($result, $parking, $data->vehicleType);

                return $parking->load(['vehicle', 'spots']);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new VehicleAlreadyParkedException;
        }
    }

    /**
     * Claim spots + window and update blocked_count on overlapping neighbors.
     * Runs inside the caller's transaction.
     */
    private function applyAllocation(
        AllocationResult $result,
        ParkingSession $parking,
        VehicleType $vehicleType,
    ): void {
        Spot::whereIn('id', $result->spotIds)
            ->update(['parking_id' => $parking->id]);

        if ($vehicleType === VehicleType::Van) {
            VanWindow::whereKey($result->windowId)
                ->update(['parking_id' => $parking->id]);

            for ($offset = 0; $offset < 3; $offset++) {
                $this->spotAllocator->bumpBlockedCountForCarSpot(
                    sectionId: $result->sectionId,
                    gridRow: $result->gridRow,
                    gridColumn: $result->startColumn + $offset,
                    excludeWindowId: $result->windowId,
                );
            }
            return;
        }

        // Car or motorcycle: 1 spot. Motorcycle spots are never part of any
        // van_window, so the bump only runs when the chosen spot is a car spot
        // (true for cars unconditionally, and for motorcycle-fallback-to-car).
        $spot = Spot::whereKey($result->spotIds[0])->first();
        if ($spot->type === SpotType::Car) {
            $this->spotAllocator->bumpBlockedCountForCarSpot(
                sectionId: $spot->section_id,
                gridRow: $spot->grid_row,
                gridColumn: $spot->grid_column,
            );
        }
    }
}
