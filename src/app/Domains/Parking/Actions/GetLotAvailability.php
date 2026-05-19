<?php

namespace App\Domains\Parking\Actions;

use App\Domains\Parking\Data\LotAvailabilityData;
use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Services\SpotAllocator;
use Illuminate\Support\Facades\DB;

final class GetLotAvailability
{
    public function __construct(
        private readonly SpotAllocator $spotAllocator,
    ) {}

    public function handle(int $parkingLotId): LotAvailabilityData
    {
        $motorcycle = SpotType::Motorcycle->value;
        $car = SpotType::Car->value;

        $stats = DB::table('spots')
            ->where('parking_lot_id', $parkingLotId)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where type = ?) as total_motorcycle", [$motorcycle])
            ->selectRaw("count(*) filter (where type = ?) as total_car", [$car])
            ->selectRaw('count(*) filter (where parking_id is null) as total_available')
            ->selectRaw("count(*) filter (where type = ? and parking_id is null) as available_motorcycle", [$motorcycle])
            ->selectRaw("count(*) filter (where type = ? and parking_id is null) as available_car", [$car])
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
