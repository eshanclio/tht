<?php

namespace App\Domains\Parking\Data;

final readonly class UnparkVehicleData
{
    public function __construct(
        public string $licensePlate,
        public int $parkingLotId,
    ) {}
}
