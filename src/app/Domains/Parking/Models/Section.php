<?php

namespace App\Domains\Parking\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
	protected $fillable = ["parking_lot_id", "name"];
}
