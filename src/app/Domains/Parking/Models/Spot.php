<?php

namespace App\Domains\Parking\Models;

use App\Domains\Parking\Data\SpotType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Spot extends Model
{
	protected $fillable = ["parking_lot_id", "section_id", "type", "position", "parking_id"];

	protected function casts(): array
	{
		return [
			"type" => SpotType::class,
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
		return $this->belongsTo(Parking::class);
	}
}
