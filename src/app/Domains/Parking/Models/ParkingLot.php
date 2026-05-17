<?php

namespace App\Domains\Parking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ParkingLot extends Model
{
    protected $fillable = ['name'];

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function spots(): HasMany
    {
        return $this->hasMany(Spot::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function parkings(): HasMany
    {
        return $this->hasMany(ParkingSession::class);
    }
}
