<?php

namespace App\Domains\Parking\Data;

readonly class UnparkVehicleData
{
	public function __construct(
		public string $licensePlate,
		public int $parkingLotId,
	) {}
}
