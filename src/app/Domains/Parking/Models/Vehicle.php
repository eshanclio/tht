<?php

namespace App\Domains\Parking\Models;

use App\Domains\Parking\Data\VehicleType;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
	protected $fillable = ["license_plate", "type"];

	protected function casts(): array
	{
		return [
			"type" => VehicleType::class,
		];
	}
}
