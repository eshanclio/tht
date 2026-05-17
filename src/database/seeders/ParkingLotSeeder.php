<?php

namespace Database\Seeders;

use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Models\ParkingLot;
use App\Domains\Parking\Models\Section;
use App\Domains\Parking\Models\Spot;
use Illuminate\Database\Seeder;

class ParkingLotSeeder extends Seeder
{
    /**
     * Seed the application's database with a fixed parking lot structure.
     */
    public function run(): void
    {
        $lot = ParkingLot::create(['name' => 'Main Lot']);

        $sections = ['A', 'B'];

        foreach ($sections as $sectionName) {
            $section = Section::create([
                'parking_lot_id' => $lot->id,
                'name' => $sectionName,
            ]);

            for ($position = 1; $position <= 5; $position++) {
                Spot::create([
                    'parking_lot_id' => $lot->id,
                    'section_id' => $section->id,
                    'type' => SpotType::Motorcycle->value,
                    'position' => $position,
                ]);
            }

            for ($position = 6; $position <= 15; $position++) {
                Spot::create([
                    'parking_lot_id' => $lot->id,
                    'section_id' => $section->id,
                    'type' => SpotType::Car->value,
                    'position' => $position,
                ]);
            }
        }
    }
}
