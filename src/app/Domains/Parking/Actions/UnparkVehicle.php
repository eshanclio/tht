<?php

namespace App\Domains\Parking\Actions;

use App\Domains\Parking\Data\UnparkVehicleData;
use App\Domains\Parking\Exceptions\VehicleNotParkedException;
use App\Domains\Parking\Models\ParkingSession;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\Vehicle;
use Illuminate\Support\Facades\DB;

final class UnparkVehicle
{
    public function handle(UnparkVehicleData $data): void
    {
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

            Spot::where('parking_id', $parking->id)
                ->update(['parking_id' => null]);
        });
    }
}
