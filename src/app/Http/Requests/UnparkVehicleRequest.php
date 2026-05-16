<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnparkVehicleRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			"license_plate" => ["required", "string", "max:255"],
			"parking_lot_id" => ["required", "integer", "exists:parking_lots,id"],
		];
	}
}
