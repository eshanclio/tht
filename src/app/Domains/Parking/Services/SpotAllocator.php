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
			->where("parking_lot_id", $parkingLotId)
			->whereNull("parking_id")
			->orderByRaw("CASE WHEN type = 'motorcycle' THEN 0 ELSE 1 END")
			->orderBy("id")
			->lockForUpdate()
			->first();

		if (!$spot) {
			throw new NoAvailableSpotException();
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
			->where("parking_lot_id", $parkingLotId)
			->where("type", SpotType::Car->value)
			->whereNull("parking_id")
			->orderBy("id")
			->lockForUpdate()
			->first();

		if (!$spot) {
			throw new NoAvailableSpotException();
		}

		return [(int) $spot->id];
	}

	/**
	 * Vans claim three consecutive car spots within the same (section, row).
	 * The gaps-and-islands query identifies the first qualifying run entirely
	 * in Postgres and returns the three lowest-column spot IDs in that run.
	 * The per-lot advisory lock (acquired by ParkVehicle) serializes vans
	 * against other vans; the re-SELECT ... FOR UPDATE on the three winners
	 * guards against a concurrent car allocation grabbing one of those rows
	 * between the scan and the UPDATE.
	 *
	 * @return array<int>
	 *
	 * @throws NoAvailableSpotException
	 */
	private function allocateVan(int $parkingLotId): array
	{
		$sql = <<<'SQL'
		    WITH groups AS (
		        SELECT id, section_id, grid_row, grid_column,
		            grid_column - ROW_NUMBER() OVER (
		                PARTITION BY section_id, grid_row
		                ORDER BY grid_column
		            ) AS grp
		        FROM spots
		        WHERE parking_lot_id = ?
		            AND type = 'car'
		            AND parking_id IS NULL
		    ),
		    runs AS (
		        SELECT section_id, grid_row, grp, MIN(grid_column) AS start_col
		        FROM groups
		        GROUP BY section_id, grid_row, grp
		        HAVING COUNT(*) >= 3
		    ),
		    winner AS (
		        SELECT section_id, grid_row, grp
		        FROM runs
		        ORDER BY section_id, grid_row, start_col
		        LIMIT 1
		    )
		    SELECT g.id
		    FROM groups g
		    JOIN winner w
		        ON g.section_id = w.section_id
		        AND g.grid_row = w.grid_row
		        AND g.grp = w.grp
		    ORDER BY g.grid_column
		    LIMIT 3
		SQL;

		$rows = DB::select($sql, [$parkingLotId]);

		if (count($rows) !== 3) {
			throw new NoAvailableSpotException();
		}

		$winningIds = array_map(fn($r) => (int) $r->id, $rows);

		$locked = Spot::query()
			->whereIn("id", $winningIds)
			->whereNull("parking_id")
			->orderBy("id")
			->lockForUpdate()
			->get();

		if ($locked->count() !== 3) {
			throw new NoAvailableSpotException();
		}

		return $winningIds;
	}

	/**
	 * Count van placements via gaps-and-islands. Free car spots within a
	 * (section, row) form consecutive column runs; subtracting ROW_NUMBER()
	 * from grid_column yields a constant value (`grp`) for each run. Each
	 * run of length L contributes floor(L / 3) non-overlapping van placements.
	 */
	public function countAvailableVanSpaces(int $parkingLotId): int
	{
		$grouped = DB::table("spots")
			->where("parking_lot_id", $parkingLotId)
			->where("type", SpotType::Car->value)
			->whereNull("parking_id")
			->selectRaw(
				"section_id, grid_row," .
					" grid_column - ROW_NUMBER() OVER (" .
					"PARTITION BY section_id, grid_row ORDER BY grid_column) AS grp",
			);

		$runs = DB::query()
			->fromSub($grouped, "g")
			->selectRaw("COUNT(*) AS run_length")
			->groupBy("section_id", "grid_row", "grp");

		return (int) DB::query()
			->fromSub($runs, "r")
			->selectRaw("COALESCE(SUM(FLOOR(run_length / 3)), 0) AS total")
			->value("total");
	}
}
