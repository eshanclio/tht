<?php

namespace App\Domains\Parking\Services;

use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Data\VehicleType;
use App\Domains\Parking\Exceptions\NoAvailableSpotException;
use App\Domains\Parking\Models\Spot;

class SpotAllocator
{
	/**
	 * @return array<int> Spot IDs to allocate.
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
	 * @return array<int>
	 * @throws NoAvailableSpotException
	 */
	private function allocateMotorcycle(int $parkingLotId): array
	{
		$spot = Spot::where("parking_lot_id", $parkingLotId)
			->where("type", SpotType::Motorcycle->value)
			->whereNull("parking_id")
			->lockForUpdate()
			->first();

		if (!$spot) {
			$spot = Spot::where("parking_lot_id", $parkingLotId)
				->where("type", SpotType::Car->value)
				->whereNull("parking_id")
				->lockForUpdate()
				->first();
		}

		if (!$spot) {
			throw new NoAvailableSpotException();
		}

		return [$spot->id];
	}

	/**
	 * @return array<int>
	 * @throws NoAvailableSpotException
	 */
	private function allocateCar(int $parkingLotId): array
	{
		$spot = Spot::where("parking_lot_id", $parkingLotId)
			->where("type", SpotType::Car->value)
			->whereNull("parking_id")
			->lockForUpdate()
			->first();

		if (!$spot) {
			throw new NoAvailableSpotException();
		}

		return [$spot->id];
	}

	/**
	 * @return array<int>
	 * @throws NoAvailableSpotException
	 */
	private function allocateVan(int $parkingLotId): array
	{
		$spots = Spot::where("parking_lot_id", $parkingLotId)
			->where("type", SpotType::Car->value)
			->whereNull("parking_id")
			->orderBy("section_id")
			->orderBy("position")
			->lockForUpdate()
			->get(["id", "section_id", "position"]);

		$grouped = $spots->groupBy("section_id");

		foreach ($grouped as $sectionSpots) {
			if ($sectionSpots->count() < 3) {
				continue;
			}

			$positions = $sectionSpots->pluck("position")->values();

			for ($i = 0; $i <= $positions->count() - 3; $i++) {
				if ($positions[$i + 2] - $positions[$i] === 2) {
					return [
						$sectionSpots[$i]->id,
						$sectionSpots[$i + 1]->id,
						$sectionSpots[$i + 2]->id,
					];
				}
			}
		}

		throw new NoAvailableSpotException();
	}

	/**
	 * Count available van spaces (used by GetLotAvailability).
	 * Reuses the same gaps-and-islands algorithm as allocation,
	 * but counts all non-overlapping van spaces per run.
	 */
	public function countAvailableVanSpaces(int $parkingLotId): int
	{
		$availableCarSpots = Spot::where("parking_lot_id", $parkingLotId)
			->where("type", SpotType::Car->value)
			->whereNull("parking_id")
			->orderBy("section_id")
			->orderBy("position")
			->get(["section_id", "position"]);

		$availableVanSpaces = 0;
		$grouped = $availableCarSpots->groupBy("section_id");

		foreach ($grouped as $sectionSpots) {
			$positions = $sectionSpots->pluck("position")->values();
			if ($positions->count() < 3) {
				continue;
			}

			$runLength = 1;
			for ($i = 1; $i < $positions->count(); $i++) {
				if ($positions[$i] - $positions[$i - 1] === 1) {
					$runLength++;
				} else {
					$availableVanSpaces += (int) floor($runLength / 3);
					$runLength = 1;
				}
			}
			$availableVanSpaces += (int) floor($runLength / 3);
		}

		return $availableVanSpaces;
	}
}
