<?php

namespace App\Domains\Parking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VanWindow extends Model
{
    protected $table = 'van_windows';

    protected $fillable = [
        'parking_lot_id',
        'section_id',
        'grid_row',
        'start_column',
        'car_spot_left_id',
        'car_spot_mid_id',
        'car_spot_right_id',
        'parking_id',
        'blocked_count',
    ];

    protected function casts(): array
    {
        return [
            'grid_row'      => 'int',
            'start_column'  => 'int',
            'blocked_count' => 'int',
        ];
    }

    public function parkingLot(): BelongsTo
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function parking(): BelongsTo
    {
        return $this->belongsTo(ParkingSession::class, 'parking_id');
    }

    public function carSpotLeft(): BelongsTo
    {
        return $this->belongsTo(Spot::class, 'car_spot_left_id');
    }

    public function carSpotMid(): BelongsTo
    {
        return $this->belongsTo(Spot::class, 'car_spot_mid_id');
    }

    public function carSpotRight(): BelongsTo
    {
        return $this->belongsTo(Spot::class, 'car_spot_right_id');
    }
}
