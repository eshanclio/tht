<?php

namespace App\Domains\Parking\Models;

use App\Domains\Parking\Data\SpotType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Spot extends Model
{
    protected $fillable = ['parking_lot_id', 'section_id', 'type', 'grid_row', 'grid_column', 'parking_id'];

    protected function casts(): array
    {
        return [
            'type' => SpotType::class,
            'grid_row' => 'int',
            'grid_column' => 'int',
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

    public function parkingSession(): BelongsTo
    {
        return $this->belongsTo(ParkingSession::class, 'parking_id');
    }
}
