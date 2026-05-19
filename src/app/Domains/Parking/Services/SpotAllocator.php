<?php

namespace App\Domains\Parking\Services;

use App\Domains\Parking\Data\AllocationResult;
use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Data\VehicleType;
use App\Domains\Parking\Exceptions\NoAvailableSpotException;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\VanWindow;
use Illuminate\Support\Facades\DB;

final class SpotAllocator
{
    /**
     * @throws NoAvailableSpotException
     */
    public function allocate(int $parkingLotId, VehicleType $vehicleType): AllocationResult
    {
        return match ($vehicleType) {
            VehicleType::Motorcycle => $this->allocateMotorcycle($parkingLotId),
            VehicleType::Car        => $this->allocateCar($parkingLotId),
            VehicleType::Van        => $this->allocateVan($parkingLotId),
        };
    }

    /**
     * Motorcycle prefers a motorcycle spot, falls back to a car spot. The
     * CASE ordering routes motorcycle rows first and falls through to car
     * automatically. SKIP LOCKED avoids waiting on a car spot held by an
     * in-flight van transaction.
     */
    private function allocateMotorcycle(int $parkingLotId): AllocationResult
    {
        $spot = Spot::query()
            ->where('parking_lot_id', $parkingLotId)
            ->whereNull('parking_id')
            ->orderByRaw("CASE WHEN type = ? THEN 0 ELSE 1 END", [SpotType::Motorcycle->value])
            ->orderBy('id')
            ->lock('FOR UPDATE SKIP LOCKED')
            ->first();

        if ($spot === null) {
            throw new NoAvailableSpotException();
        }

        return new AllocationResult(spotIds: [(int) $spot->id]);
    }

    private function allocateCar(int $parkingLotId): AllocationResult
    {
        $spot = Spot::query()
            ->where('parking_lot_id', $parkingLotId)
            ->where('type', SpotType::Car->value)
            ->whereNull('parking_id')
            ->orderBy('id')
            ->lock('FOR UPDATE SKIP LOCKED')
            ->first();

        if ($spot === null) {
            throw new NoAvailableSpotException();
        }

        return new AllocationResult(spotIds: [(int) $spot->id]);
    }

    /**
     * Van allocation: one atomic SELECT acquires row locks on the candidate
     * van_window AND its 3 underlying car spots via FOR UPDATE OF w, sl, sm,
     * sr SKIP LOCKED. If any of the 4 rows is locked or no longer satisfies
     * the WHERE predicate, the candidate is skipped. No advisory lock and no
     * allocator-level retry — transaction-level deadlock retry is handled by
     * the caller via DB::transaction(..., attempts: 3).
     */
    private function allocateVan(int $parkingLotId): AllocationResult
    {
        $picked = VanWindow::query()
            ->from('van_windows as w')
            ->join('spots as sl', 'sl.id', '=', 'w.car_spot_left_id')
            ->join('spots as sm', 'sm.id', '=', 'w.car_spot_mid_id')
            ->join('spots as sr', 'sr.id', '=', 'w.car_spot_right_id')
            ->where('w.parking_lot_id', $parkingLotId)
            ->whereNull('w.parking_id')
            ->where('w.blocked_count', 0)
            ->whereNull('sl.parking_id')
            ->whereNull('sm.parking_id')
            ->whereNull('sr.parking_id')
            ->orderBy('w.id')
            ->limit(1)
            ->lock('FOR UPDATE OF w, sl, sm, sr SKIP LOCKED')
            ->select([
                'w.id                 as window_id',
                'w.section_id         as section_id',
                'w.grid_row           as grid_row',
                'w.start_column       as start_column',
                'w.car_spot_left_id   as left_id',
                'w.car_spot_mid_id    as mid_id',
                'w.car_spot_right_id  as right_id',
            ])
            ->first();

        if ($picked === null) {
            throw new NoAvailableSpotException();
        }

        return new AllocationResult(
            spotIds: [
                (int) $picked->left_id,
                (int) $picked->mid_id,
                (int) $picked->right_id,
            ],
            windowId: (int) $picked->window_id,
            sectionId: (int) $picked->section_id,
            gridRow: (int) $picked->grid_row,
            startColumn: (int) $picked->start_column,
        );
    }

    /**
     * Increment blocked_count on the up-to-3 van_windows that overlap a
     * car spot that just became occupied. excludeWindowId is set when a
     * van is the writer — the van's own window does not block itself.
     */
    public function bumpBlockedCountForCarSpot(
        int $sectionId,
        int $gridRow,
        int $gridColumn,
        ?int $excludeWindowId = null,
    ): void {
        $this->adjustBlockedCount($sectionId, $gridRow, $gridColumn, $excludeWindowId, delta: 1);
    }

    /**
     * Symmetric to bumpBlockedCountForCarSpot. excludeWindowId is set when
     * the spot is being freed because a van that owned it is unparking.
     */
    public function decrementBlockedCountForCarSpot(
        int $sectionId,
        int $gridRow,
        int $gridColumn,
        ?int $excludeWindowId = null,
    ): void {
        $this->adjustBlockedCount($sectionId, $gridRow, $gridColumn, $excludeWindowId, delta: -1);
    }

    private function adjustBlockedCount(
        int $sectionId,
        int $gridRow,
        int $gridColumn,
        ?int $excludeWindowId,
        int $delta,
    ): void {
        $query = VanWindow::query()
            ->where('section_id', $sectionId)
            ->where('grid_row', $gridRow)
            ->whereBetween('start_column', [$gridColumn - 2, $gridColumn]);

        if ($excludeWindowId !== null) {
            $query->whereKeyNot($excludeWindowId);
        }

        $expression = $delta >= 0
            ? 'blocked_count + '.$delta
            : 'blocked_count - '.abs($delta);

        $query->update(['blocked_count' => DB::raw($expression)]);
    }

    /**
     * Read-time gaps-and-islands on van_windows: returns the actual number of
     * non-overlapping vans that can park right now. A chain of L available
     * windows corresponds to L+2 free car spots → floor((L+2)/3) vans per chain.
     */
    public function countAvailableVanSpaces(int $parkingLotId): int
    {
        $runs = DB::table('van_windows')
            ->where('parking_lot_id', $parkingLotId)
            ->whereNull('parking_id')
            ->where('blocked_count', 0)
            ->selectRaw(
                'section_id, grid_row,'
                .' start_column - ROW_NUMBER() OVER ('
                .' PARTITION BY section_id, grid_row ORDER BY start_column'
                .') AS grp'
            );

        $lengths = DB::query()
            ->fromSub($runs, 'r')
            ->selectRaw('COUNT(*) AS run_length')
            ->groupBy('section_id', 'grid_row', 'grp');

        return (int) DB::query()
            ->fromSub($lengths, 'l')
            ->selectRaw('COALESCE(SUM(FLOOR((run_length + 2) / 3)), 0) AS total')
            ->value('total');
    }
}
