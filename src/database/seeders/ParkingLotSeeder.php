<?php

namespace Database\Seeders;

use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Models\ParkingLot;
use App\Domains\Parking\Models\Section;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\VanWindow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class ParkingLotSeeder extends Seeder
{
    public function run(): void
    {
        $lot = ParkingLot::firstOrCreate(['name' => 'Main Lot']);

        $now = Carbon::now();
        $rows = [];

        foreach (['A', 'B'] as $sectionName) {
            $section = Section::firstOrCreate([
                'parking_lot_id' => $lot->id,
                'name' => $sectionName,
            ]);

            // Row 1: 5 motorcycle spots (columns 1..5).
            // Rows 2-3: 5 car spots each (columns 1..5).
            for ($row = 1; $row <= 3; $row++) {
                $type = $row === 1
                    ? SpotType::Motorcycle->value
                    : SpotType::Car->value;

                for ($column = 1; $column <= 5; $column++) {
                    $rows[] = [
                        'parking_lot_id' => $lot->id,
                        'section_id' => $section->id,
                        'type' => $type,
                        'grid_row' => $row,
                        'grid_column' => $column,
                        'parking_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        // `insertOrIgnore` relies on the (section_id, grid_row, grid_column)
        // unique index to make re-running `db:seed` a no-op.
        Spot::insertOrIgnore($rows);

        // Materialize sliding-window van candidates: for each (section, row)
        // of car spots, emit one window per consecutive triple of columns.
        // UNIQUE(section_id, grid_row, start_column) makes this idempotent.
        $carSpots = Spot::query()
            ->where('parking_lot_id', $lot->id)
            ->where('type', SpotType::Car->value)
            ->orderBy('section_id')
            ->orderBy('grid_row')
            ->orderBy('grid_column')
            ->get(['id', 'parking_lot_id', 'section_id', 'grid_row', 'grid_column']);

        $windowRows = [];
        $byRow = $carSpots->groupBy(fn (Spot $s) => "{$s->section_id}:{$s->grid_row}");

        foreach ($byRow as $rowSpots) {
            $rowSpots = $rowSpots->values();
            $count = $rowSpots->count();

            for ($i = 0; $i + 2 < $count; $i++) {
                $l = $rowSpots[$i];
                $m = $rowSpots[$i + 1];
                $r = $rowSpots[$i + 2];

                // Skip non-consecutive triples (defends against future aisle gaps).
                if ($r->grid_column - $l->grid_column !== 2) {
                    continue;
                }

                $windowRows[] = [
                    'parking_lot_id'    => $l->parking_lot_id,
                    'section_id'        => $l->section_id,
                    'grid_row'          => $l->grid_row,
                    'start_column'      => $l->grid_column,
                    'car_spot_left_id'  => $l->id,
                    'car_spot_mid_id'   => $m->id,
                    'car_spot_right_id' => $r->id,
                    'parking_id'        => null,
                    'blocked_count'     => 0,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
        }

        VanWindow::insertOrIgnore($windowRows);
    }
}
