<?php

namespace App\Domains\Parking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Parking extends Model
{
	public $timestamps = false;

	protected $fillable = ["parking_lot_id", "vehicle_id", "started_at", "ended_at"];

	protected function casts(): array
	{
		return [
			"started_at" => "datetime",
			"ended_at" => "datetime",
		];
	}

	public function parkingLot(): BelongsTo
	{
		return $this->belongsTo(ParkingLot::class);
	}

	public function vehicle(): BelongsTo
	{
		return $this->belongsTo(Vehicle::class);
	}

	public function spots(): HasMany
	{
		return $this->hasMany(Spot::class);
	}
}
