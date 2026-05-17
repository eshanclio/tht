<?php

namespace App\Domains\Parking\Data;

final readonly class LotAvailabilityData
{
    public function __construct(
        public int $totalMotorcycleSpots,
        public int $availableMotorcycleSpots,
        public int $totalCarSpots,
        public int $availableCarSpots,
        public int $totalCapacity,
        public int $totalAvailable,
        public int $availableVanSpaces,
    ) {}
}
