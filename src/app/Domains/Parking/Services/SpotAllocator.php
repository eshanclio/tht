<?php

namespace App\Domains\Parking\Services;

use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Data\VehicleType;
use App\Domains\Parking\Exceptions\NoAvailableSpotException;
use App\Domains\Parking\Models\Spot;
use Illuminate\Support\Facades\DB;

final class SpotAllocator
{
    /**
     * @return array<int> Spot IDs to allocate.
     *
     * @throws NoAvailableSpotException
     */
    public function allocate(int $parkingLotId, VehicleType $vehicleType): array
    {
        return match ($vehicleType) {
            VehicleType::Motorcycle => $this->allocateMotorcycle($parkingLotId),
            VehicleType::Car => $this->allocateCar($parkingLotId),
            VehicleType::Van => $this->allocateVan($parkingLotId),
        };
    }

    /**
     * Motorcycles prefer a motorcycle spot but fall back to a car spot.
     * One ordered query covers both: motorcycle rows sort first via CASE.
     *
     * @return array<int>
     *
     * @throws NoAvailableSpotException
     */
    private function allocateMotorcycle(int $parkingLotId): array
    {
        $spot = Spot::query()
            ->where('parking_lot_id', $parkingLotId)
            ->whereNull('parking_id')
            ->orderByRaw("CASE WHEN type = 'motorcycle' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $spot) {
            throw new NoAvailableSpotException;
        }

        return [(int) $spot->id];
    }

    /**
     * @return array<int>
     *
     * @throws NoAvailableSpotException
     */
    private function allocateCar(int $parkingLotId): array
    {
        $spot = Spot::query()
            ->where('parking_lot_id', $parkingLotId)
            ->where('type', SpotType::Car->value)
            ->whereNull('parking_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $spot) {
            throw new NoAvailableSpotException;
        }

        return [(int) $spot->id];
    }

    /**
     * Vans claim three consecutive car spots within the same section.
     * The per-lot advisory lock (acquired by ParkVehicle) serializes vans
     * against other vans on the same lot. The initial scan does not issue
     * row locks, so the re-SELECT ... FOR UPDATE on the three winners is
     * the guard against a concurrent car allocation grabbing one of those
     * rows between the scan and the UPDATE.
     *
     * @return array<int>
     *
     * @throws NoAvailableSpotException
     */
    private function allocateVan(int $parkingLotId): array
    {
        $spots = Spot::query()
            ->where('parking_lot_id', $parkingLotId)
            ->where('type', SpotType::Car->value)
            ->whereNull('parking_id')
            ->orderBy('section_id')
            ->orderBy('position')
            ->get(['id', 'section_id', 'position']);

        $winningIds = null;

        foreach ($spots->groupBy('section_id') as $sectionSpots) {
            if ($sectionSpots->count() < 3) {
                continue;
            }

            $values = $sectionSpots->values();
            $positions = $values->pluck('position');

            for ($i = 0; $i <= $positions->count() - 3; $i++) {
                if ($positions[$i + 2] - $positions[$i] === 2) {
                    $winningIds = [
                        (int) $values[$i]->id,
                        (int) $values[$i + 1]->id,
                        (int) $values[$i + 2]->id,
                    ];
                    break 2;
                }
            }
        }

        if ($winningIds === null) {
            throw new NoAvailableSpotException;
        }

        $locked = Spot::query()
            ->whereIn('id', $winningIds)
            ->whereNull('parking_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($locked->count() !== 3) {
            throw new NoAvailableSpotException;
        }

        return $winningIds;
    }

    /**
     * Count van placements via gaps-and-islands. Free car spots within a
     * section form consecutive runs; subtracting ROW_NUMBER() from position
     * yields a constant value (`grp`) for each run. Each run of length L
     * contributes floor(L / 3) non-overlapping van placements.
     */
    public function countAvailableVanSpaces(int $parkingLotId): int
    {
        $grouped = DB::table('spots')
            ->where('parking_lot_id', $parkingLotId)
            ->where('type', SpotType::Car->value)
            ->whereNull('parking_id')
            ->selectRaw(
                'section_id, position - ROW_NUMBER() OVER ('
                .'PARTITION BY section_id ORDER BY position) AS grp'
            );

        $runs = DB::query()
            ->fromSub($grouped, 'g')
            ->selectRaw('COUNT(*) AS run_length')
            ->groupBy('section_id', 'grp');

        return (int) DB::query()
            ->fromSub($runs, 'r')
            ->selectRaw('COALESCE(SUM(FLOOR(run_length / 3)), 0) AS total')
            ->value('total');
    }
}
