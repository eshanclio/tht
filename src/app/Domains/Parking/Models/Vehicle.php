<?php

namespace App\Domains\Parking\Models;

use App\Domains\Parking\Data\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Vehicle extends Model
{
    protected $fillable = ['parking_lot_id', 'license_plate', 'type'];

    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
        ];
    }

    public function parkingLot(): BelongsTo
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function parkings(): HasMany
    {
        return $this->hasMany(ParkingSession::class);
    }
}
