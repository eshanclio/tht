<?php

namespace App\Domains\Parking\Actions;

use App\Domains\Parking\Data\ParkVehicleData;
use App\Domains\Parking\Exceptions\VehicleAlreadyParkedException;
use App\Domains\Parking\Models\Parking;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\Vehicle;
use App\Domains\Parking\Services\SpotAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParkVehicle
{
	public function __construct(
		private SpotAllocator $spotAllocator,
	) {}

	public function handle(ParkVehicleData $data): Parking
	{
		$vehicle = Vehicle::createOrFirst(
			["license_plate" => $data->licensePlate],
			["type" => $data->vehicleType->value]
		);

		if ($vehicle->type->value !== $data->vehicleType->value) {
			throw ValidationException::withMessages([
				"vehicle_type" => "Vehicle type does not match the existing record.",
			]);
		}

		try {
			return DB::transaction(function () use ($data, $vehicle) {
				DB::statement(
					"SELECT pg_advisory_xact_lock(1, ?)",
					[$data->parkingLotId]
				);

				$existingParking = Parking::where("vehicle_id", $vehicle->id)
					->whereNull("ended_at")
					->lockForUpdate()
					->first();

				if ($existingParking) {
					throw new VehicleAlreadyParkedException();
				}

				$spotIds = $this->spotAllocator->allocate($data->parkingLotId, $data->vehicleType);

				$parking = Parking::create([
					"parking_lot_id" => $data->parkingLotId,
					"vehicle_id" => $vehicle->id,
					"started_at" => now(),
				]);

				Spot::whereIn("id", $spotIds)
					->update(["parking_id" => $parking->id]);

				$parking->load(["vehicle", "spots"]);

				return $parking;
			});
		} catch (UniqueConstraintViolationException) {
			throw new VehicleAlreadyParkedException();
		}
	}
}
