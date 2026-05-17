<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ParkingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'license_plate' => $this->whenLoaded(
                'vehicle',
                fn () => $this->vehicle->license_plate,
            ),
            'vehicle_type' => $this->whenLoaded(
                'vehicle',
                fn () => $this->vehicle->type->value,
            ),
            'parking_lot_id' => $this->parking_lot_id,
            'started_at' => $this->started_at,
            'spots' => $this->whenLoaded(
                'spots',
                fn () => $this->spots->map(fn ($spot) => [
                    'id' => $spot->id,
                    'type' => $spot->type->value,
                    'section_id' => $spot->section_id,
                    'grid_row' => $spot->grid_row,
                    'grid_column' => $spot->grid_column,
                ])->values(),
            ),
        ];
    }
}
