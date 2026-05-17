<?php

namespace App\Domains\Parking\Data;

final readonly class ParkVehicleData
{
    public function __construct(
        public string $licensePlate,
        public VehicleType $vehicleType,
        public int $parkingLotId,
    ) {}
}
