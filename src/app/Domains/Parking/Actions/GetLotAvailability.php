<?php

namespace App\Domains\Parking\Actions;

use App\Domains\Parking\Data\LotAvailabilityData;
use App\Domains\Parking\Services\SpotAllocator;
use Illuminate\Support\Facades\DB;

class GetLotAvailability
{
	public function __construct(
		private SpotAllocator $spotAllocator,
	) {}

	public function handle(int $parkingLotId): LotAvailabilityData
	{
		$stats = DB::table("spots")
			->where("parking_lot_id", $parkingLotId)
			->selectRaw("count(*) as total")
			->selectRaw("count(*) filter (where type = 'motorcycle') as total_motorcycle")
			->selectRaw("count(*) filter (where type = 'car') as total_car")
			->selectRaw("count(*) filter (where parking_id is null) as total_available")
			->selectRaw("count(*) filter (where type = 'motorcycle' and parking_id is null) as available_motorcycle")
			->selectRaw("count(*) filter (where type = 'car' and parking_id is null) as available_car")
			->first();

		$availableVanSpaces = $this->spotAllocator->countAvailableVanSpaces($parkingLotId);

		return new LotAvailabilityData(
			totalMotorcycleSpots: (int) $stats->total_motorcycle,
			availableMotorcycleSpots: (int) $stats->available_motorcycle,
			totalCarSpots: (int) $stats->total_car,
			availableCarSpots: (int) $stats->available_car,
			totalCapacity: (int) $stats->total,
			totalAvailable: (int) $stats->total_available,
			availableVanSpaces: $availableVanSpaces,
		);
	}
}
