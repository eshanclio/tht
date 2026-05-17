<?php

namespace App\Http\Controllers;

use App\Domains\Parking\Actions\GetLotAvailability;
use App\Domains\Parking\Actions\ParkVehicle;
use App\Domains\Parking\Actions\UnparkVehicle;
use App\Domains\Parking\Data\ParkVehicleData;
use App\Domains\Parking\Data\UnparkVehicleData;
use App\Domains\Parking\Models\ParkingLot;
use App\Http\Requests\ParkVehicleRequest;
use App\Http\Resources\AvailabilityResource;
use App\Http\Resources\ParkingResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ParkingController
{
    public function park(ParkVehicleRequest $request, ParkingLot $parkingLot, ParkVehicle $action): JsonResponse
    {
        $data = new ParkVehicleData(
            licensePlate: $request->string('license_plate')->toString(),
            vehicleType: $request->vehicleType(),
            parkingLotId: $parkingLot->id,
        );

        $parking = $action->handle($data);

        return (new ParkingResource($parking))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function unpark(ParkingLot $parkingLot, string $licensePlate, UnparkVehicle $action): JsonResponse
    {
        $data = new UnparkVehicleData(
            licensePlate: $licensePlate,
            parkingLotId: $parkingLot->id,
        );

        $action->handle($data);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function availability(ParkingLot $parkingLot, GetLotAvailability $action): JsonResponse
    {
        $data = $action->handle($parkingLot->id);

        return (new AvailabilityResource($data))->response();
    }
}
