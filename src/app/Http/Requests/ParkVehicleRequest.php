<?php

namespace App\Http\Requests;

use App\Domains\Parking\Data\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParkVehicleRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			"license_plate" => ["required", "string", "max:255"],
			"vehicle_type" => ["required", "string", Rule::enum(VehicleType::class)],
			"parking_lot_id" => ["required", "integer", "exists:parking_lots,id"],
		];
	}

	public function vehicleType(): VehicleType
	{
		return VehicleType::from($this->input("vehicle_type"));
	}
}
