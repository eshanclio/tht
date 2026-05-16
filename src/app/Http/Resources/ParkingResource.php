<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParkingResource extends JsonResource
{
	public function toArray(Request $request): array
	{
		return [
			"id" => $this->id,
			"license_plate" => $this->vehicle->license_plate,
			"vehicle_type" => $this->vehicle->type,
			"parking_lot_id" => $this->parking_lot_id,
			"started_at" => $this->started_at,
			"spots" => $this->spots->map(fn ($spot) => [
				"id" => $spot->id,
				"type" => $spot->type,
				"section_id" => $spot->section_id,
				"position" => $spot->position,
			]),
		];
	}
}
