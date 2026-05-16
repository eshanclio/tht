<?php

namespace App\Domains\Parking\Actions;

use App\Domains\Parking\Data\UnparkVehicleData;
use App\Domains\Parking\Exceptions\VehicleNotParkedException;
use App\Domains\Parking\Models\Parking;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\Vehicle;
use Illuminate\Support\Facades\DB;

class UnparkVehicle
{
	public function handle(UnparkVehicleData $data): void
	{
		$vehicle = Vehicle::where("license_plate", $data->licensePlate)->first();

		if (!$vehicle) {
			throw new VehicleNotParkedException();
		}

		DB::transaction(function () use ($data, $vehicle) {
			$parking = Parking::where("vehicle_id", $vehicle->id)
				->where("parking_lot_id", $data->parkingLotId)
				->whereNull("ended_at")
				->lockForUpdate()
				->first();

			if (!$parking) {
				throw new VehicleNotParkedException();
			}

			$parking->update(["ended_at" => now()]);

			Spot::where("parking_id", $parking->id)
				->update(["parking_id" => null]);
		});
	}
}
