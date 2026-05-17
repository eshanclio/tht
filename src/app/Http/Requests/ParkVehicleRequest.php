<?php

namespace App\Http\Requests;

use App\Domains\Parking\Data\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ParkVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'license_plate' => ['required', 'string', 'max:255'],
            'vehicle_type' => ['required', Rule::enum(VehicleType::class)],
        ];
    }

    public function vehicleType(): VehicleType
    {
        return $this->enum('vehicle_type', VehicleType::class);
    }
}
