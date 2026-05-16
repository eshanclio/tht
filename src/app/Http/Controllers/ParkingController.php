<?php

namespace App\Http\Controllers;

use App\Domains\Parking\Actions\GetLotAvailability;
use App\Domains\Parking\Actions\ParkVehicle;
use App\Domains\Parking\Actions\UnparkVehicle;
use App\Domains\Parking\Data\ParkVehicleData;
use App\Domains\Parking\Data\UnparkVehicleData;
use App\Domains\Parking\Models\ParkingLot;
use App\Http\Requests\ParkVehicleRequest;
use App\Http\Requests\UnparkVehicleRequest;
use App\Http\Resources\AvailabilityResource;
use App\Http\Resources\ParkingResource;
use Illuminate\Http\JsonResponse;

class ParkingController extends Controller
{
	public function park(ParkVehicleRequest $request, ParkVehicle $action): JsonResponse
	{
		$data = new ParkVehicleData(
			licensePlate: $request->input("license_plate"),
			vehicleType: $request->vehicleType(),
			parkingLotId: (int) $request->input("parking_lot_id"),
		);

		$parking = $action->handle($data);

		return (new ParkingResource($parking))
			->response()
			->setStatusCode(201);
	}

	public function unpark(UnparkVehicleRequest $request, UnparkVehicle $action): JsonResponse
	{
		$data = new UnparkVehicleData(
			licensePlate: $request->input("license_plate"),
			parkingLotId: (int) $request->input("parking_lot_id"),
		);

		$action->handle($data);

		return response()->json(null, 204);
	}

	public function availability(ParkingLot $parkingLot, GetLotAvailability $action): JsonResponse
	{
		$data = $action->handle($parkingLot->id);

		return (new AvailabilityResource($data))->response();
	}
}
