<?php

namespace App\Domains\Parking\Data;

enum VehicleType: string
{
	case Motorcycle = "motorcycle";
	case Car = "car";
	case Van = "van";
}
