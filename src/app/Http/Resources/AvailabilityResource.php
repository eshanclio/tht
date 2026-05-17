<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AvailabilityResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return [
            'total_motorcycle_spots' => $this->totalMotorcycleSpots,
            'available_motorcycle_spots' => $this->availableMotorcycleSpots,
            'total_car_spots' => $this->totalCarSpots,
            'available_car_spots' => $this->availableCarSpots,
            'total_capacity' => $this->totalCapacity,
            'total_available' => $this->totalAvailable,
            'available_van_spaces' => $this->availableVanSpaces,
        ];
    }
}
