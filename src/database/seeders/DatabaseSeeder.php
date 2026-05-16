<?php

namespace Database\Seeders;

use Database\Seeders\ParkingLotSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ParkingLotSeeder::class);
    }
}
