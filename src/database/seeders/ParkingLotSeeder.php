<?php

namespace Database\Seeders;

use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Models\ParkingLot;
use App\Domains\Parking\Models\Section;
use App\Domains\Parking\Models\Spot;
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
    }
}
