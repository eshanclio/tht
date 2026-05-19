<?php

namespace Tests\Feature;

use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Data\VehicleType;
use App\Domains\Parking\Models\ParkingLot;
use App\Domains\Parking\Models\ParkingSession;
use App\Domains\Parking\Models\Section;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\VanWindow;
use App\Domains\Parking\Models\Vehicle;
use Database\Seeders\ParkingLotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ParkingApiTest extends TestCase
{
    use RefreshDatabase;

    protected int $lotId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ParkingLotSeeder::class);
        $this->lotId = ParkingLot::first()->id;
    }

    // -----------------------------------------------------------------
    // Group 1: Golden Path (Happy Path)
    // -----------------------------------------------------------------

    public function test_motorcycle_parks_in_a_motorcycle_spot(): void
    {
        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'MOTO-001',
            'vehicle_type' => VehicleType::Motorcycle->value,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('vehicle_type', VehicleType::Motorcycle->value);

        $parkingId = $response->json('id');
        $this->assertDatabaseHas('parkings', ['id' => $parkingId]);
        $this->assertDatabaseHas('spots', ['parking_id' => $parkingId, 'type' => SpotType::Motorcycle->value]);
    }

    public function test_motorcycle_parks_in_a_car_spot_when_motorcycle_spots_are_full(): void
    {
        $this->occupyAllMotorcycleSpots($this->lotId);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'MOTO-002',
            'vehicle_type' => VehicleType::Motorcycle->value,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('vehicle_type', VehicleType::Motorcycle->value);

        $spotType = Spot::where('parking_id', $response->json('id'))->value('type');
        $this->assertSame(SpotType::Car, $spotType);
    }

    public function test_car_parks_in_a_car_spot(): void
    {
        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'CAR-001',
            'vehicle_type' => VehicleType::Car->value,
        ]);

        $response->assertStatus(201);

        $parkingId = $response->json('id');
        $this->assertDatabaseHas('spots', ['parking_id' => $parkingId, 'type' => SpotType::Car->value]);
    }

    public function test_van_parks_in_three_consecutive_car_spots(): void
    {
        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-001',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $parkingId = $response->json('id');
        $spots = Spot::where('parking_id', $parkingId)->get();
        $this->assertCount(3, $spots);

        $sectionIds = $spots->pluck('section_id')->unique();
        $this->assertCount(1, $sectionIds);

        $gridRows = $spots->pluck('grid_row')->unique();
        $this->assertCount(1, $gridRows, 'All three spots must share one grid_row');

        $columns = $spots->pluck('grid_column')->sort()->values();
        $this->assertSame($columns[0] + 1, $columns[1]);
        $this->assertSame($columns[1] + 1, $columns[2]);
    }

    public function test_van_parks_in_middle_of_row_window(): void
    {
        $parkingId = $this->createDummyParking();
        $sectionA = Section::where('parking_lot_id', $this->lotId)->where('name', 'A')->first();

        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        Spot::where('section_id', $sectionA->id)
            ->where('grid_row', 2)
            ->whereIn('grid_column', [2, 3, 4])
            ->update(['parking_id' => null]);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-MID',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $columns = Spot::where('parking_id', $response->json('id'))
            ->orderBy('grid_column')
            ->pluck('grid_column')
            ->toArray();

        $this->assertSame([2, 3, 4], $columns);
    }

    public function test_van_parks_in_end_of_row_window(): void
    {
        $parkingId = $this->createDummyParking();
        $sectionA = Section::where('parking_lot_id', $this->lotId)->where('name', 'A')->first();

        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        Spot::where('section_id', $sectionA->id)
            ->where('grid_row', 2)
            ->whereIn('grid_column', [3, 4, 5])
            ->update(['parking_id' => null]);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-END',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $columns = Spot::where('parking_id', $response->json('id'))
            ->orderBy('grid_column')
            ->pluck('grid_column')
            ->toArray();

        $this->assertSame([3, 4, 5], $columns);
    }

    public function test_van_prefers_lower_section_id_when_both_sections_have_valid_runs(): void
    {
        $parkingId = $this->createDummyParking();
        $sectionA = Section::where('parking_lot_id', $this->lotId)->where('name', 'A')->first();
        $sectionB = Section::where('parking_lot_id', $this->lotId)->where('name', 'B')->first();

        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        foreach ([$sectionA, $sectionB] as $section) {
            Spot::where('section_id', $section->id)
                ->where('grid_row', 2)
                ->whereIn('grid_column', [1, 2, 3])
                ->update(['parking_id' => null]);
        }

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-SECT',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $spots = Spot::where('parking_id', $response->json('id'))->get();
        $this->assertCount(3, $spots);
        $this->assertTrue(
            $spots->every(fn ($s) => $s->section_id === $sectionA->id),
            'Allocator should pick the lower section_id (A) when both sections have a valid run'
        );
    }

    public function test_van_allocates_correctly_when_spots_inserted_in_non_ascending_column_order(): void
    {
        $lot = ParkingLot::create(['name' => 'Shuffle Lot']);
        $section = Section::create(['parking_lot_id' => $lot->id, 'name' => 'S']);

        $now = now();
        // Insert in descending order to verify orderBy('grid_column') is load-bearing.
        Spot::insert(array_map(fn ($col) => [
            'parking_lot_id' => $lot->id,
            'section_id' => $section->id,
            'type' => SpotType::Car->value,
            'grid_row' => 1,
            'grid_column' => $col,
            'parking_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], [5, 4, 3, 2, 1]));

        $insertedSpots = Spot::where('section_id', $section->id)
            ->orderBy('grid_column')
            ->get(['id', 'parking_lot_id', 'section_id', 'grid_row', 'grid_column']);

        $windowRows = [];
        for ($i = 0; $i + 2 < $insertedSpots->count(); $i++) {
            $l = $insertedSpots[$i];
            $m = $insertedSpots[$i + 1];
            $r = $insertedSpots[$i + 2];
            $windowRows[] = [
                'parking_lot_id' => $l->parking_lot_id,
                'section_id' => $l->section_id,
                'grid_row' => $l->grid_row,
                'start_column' => $l->grid_column,
                'car_spot_left_id' => $l->id,
                'car_spot_mid_id' => $m->id,
                'car_spot_right_id' => $r->id,
                'parking_id' => null,
                'blocked_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        VanWindow::insert($windowRows);

        $response = $this->postJson("/api/parking-lots/{$lot->id}/sessions", [
            'license_plate' => 'VAN-SORT',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $columns = Spot::where('parking_id', $response->json('id'))
            ->orderBy('grid_column')
            ->pluck('grid_column')
            ->toArray();

        $this->assertSame([1, 2, 3], $columns);
    }

    public function test_unpark_frees_associated_spots(): void
    {
        $this->freezeTime();

        $parkResponse = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'CAR-UNPARK',
            'vehicle_type' => VehicleType::Car->value,
        ]);

        $parkingId = $parkResponse->json('id');
        $spotId = Spot::where('parking_id', $parkingId)->value('id');

        $this->deleteJson("/api/parking-lots/{$this->lotId}/vehicles/CAR-UNPARK")
            ->assertStatus(204);

        $this->assertDatabaseHas('parkings', [
            'id' => $parkingId,
            'ended_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $spot = Spot::find($spotId);
        $this->assertNull($spot->parking_id);
    }

    public function test_unpark_van_frees_all_three_spots(): void
    {
        $this->freezeTime();

        $parkResponse = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-UNPARK',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $parkResponse->assertStatus(201);

        $parkingId = $parkResponse->json('id');
        $spotIds = Spot::where('parking_id', $parkingId)->pluck('id');
        $this->assertCount(3, $spotIds);

        $this->deleteJson("/api/parking-lots/{$this->lotId}/vehicles/VAN-UNPARK")
            ->assertStatus(204);

        foreach ($spotIds as $spotId) {
            $this->assertDatabaseHas('spots', [
                'id' => $spotId,
                'parking_id' => null,
            ]);
        }

        $this->assertDatabaseHas('parkings', [
            'id' => $parkingId,
            'ended_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_van_succeeds_with_horizontal_run_in_single_row(): void
    {
        $parkingId = $this->createDummyParking();
        $sectionA = Section::where('parking_lot_id', $this->lotId)
            ->where('name', 'A')
            ->first();

        // Occupy all car spots, free exactly row 2 columns 1-3 in section A.
        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        Spot::where('section_id', $sectionA->id)
            ->where('grid_row', 2)
            ->whereIn('grid_column', [1, 2, 3])
            ->update(['parking_id' => null]);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-HORIZ',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $spots = Spot::where('parking_id', $response->json('id'))->get();
        $this->assertSame([2, 2, 2], $spots->pluck('grid_row')->toArray());
        $this->assertEqualsCanonicalizing([1, 2, 3], $spots->pluck('grid_column')->toArray());
    }

    public function test_van_picks_the_only_valid_row_when_others_are_fragmented(): void
    {
        $parkingId = $this->createDummyParking();
        $sectionA = Section::where('parking_lot_id', $this->lotId)
            ->where('name', 'A')
            ->first();

        // Occupy everything, then:
        //   Row 2 of A: free cols 1, 2, 4   (fragmented — no run of 3)
        //   Row 3 of A: free cols 1, 2, 3   (valid run of 3)
        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        Spot::where('section_id', $sectionA->id)
            ->where('grid_row', 2)
            ->whereIn('grid_column', [1, 2, 4])
            ->update(['parking_id' => null]);

        Spot::where('section_id', $sectionA->id)
            ->where('grid_row', 3)
            ->whereIn('grid_column', [1, 2, 3])
            ->update(['parking_id' => null]);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-PICK-ROW',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $spots = Spot::where('parking_id', $response->json('id'))->get();
        $this->assertSame([3, 3, 3], $spots->pluck('grid_row')->toArray());
        $this->assertEqualsCanonicalizing([1, 2, 3], $spots->pluck('grid_column')->toArray());
    }

    // -----------------------------------------------------------------
    // Group 2: Rejection / Error Cases
    // -----------------------------------------------------------------

    public function test_car_is_rejected_when_only_motorcycle_spots_remain(): void
    {
        $this->occupyAllCarSpots($this->lotId);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'CAR-FAIL',
            'vehicle_type' => VehicleType::Car->value,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'No available spot for this vehicle type.');
        $this->assertDatabaseCount('parkings', 1);
    }

    public function test_van_is_rejected_when_car_spots_are_fragmented(): void
    {
        $parkingId = $this->createDummyParking();
        $sections = Section::where('parking_lot_id', $this->lotId)->get();

        // Occupy every car spot, then free pairs that never form 3-in-a-row
        // within a single (section, grid_row). Each car row keeps free
        // columns 1-2 and 4-5 — the missing column 3 breaks the run.
        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        foreach ($sections as $section) {
            Spot::query()
                ->where('section_id', $section->id)
                ->where('type', SpotType::Car->value)
                ->whereIn('grid_column', [1, 2, 4, 5])
                ->update(['parking_id' => null]);
        }

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-FRAG',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('parkings', 1);
    }

    public function test_van_is_rejected_when_fewer_than_three_car_spots_exist(): void
    {
        $parkingId = $this->createDummyParking();
        $sectionA = Section::where('parking_lot_id', $this->lotId)
            ->where('name', 'A')
            ->first();

        // Occupy every car spot, then free exactly 2 consecutive spots in one row.
        // 2 < 3, so the allocator's row-count guard rejects before even checking
        // for a sliding window of 3 — distinct from the fragmentation scenario.
        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        Spot::where('section_id', $sectionA->id)
            ->where('grid_row', 2)
            ->whereIn('grid_column', [1, 2])
            ->update(['parking_id' => null]);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-FEW',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('parkings', 1);
    }

    public function test_park_fails_when_vehicle_is_already_parked(): void
    {
        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'DUP-001',
            'vehicle_type' => VehicleType::Car->value,
        ])->assertStatus(201);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'DUP-001',
            'vehicle_type' => VehicleType::Car->value,
        ]);

        $response->assertStatus(409);
    }

    public function test_unpark_fails_when_vehicle_is_not_currently_parked(): void
    {
        $response = $this->deleteJson("/api/parking-lots/{$this->lotId}/vehicles/NEVER-PARKED");

        $response->assertStatus(404);
    }

    public function test_unpark_fails_when_parking_lot_does_not_exist(): void
    {
        // parking_lot_id is now a URL segment resolved via route model binding;
        // an unknown lot returns 404 rather than a 422 validation error.
        $response = $this->deleteJson('/api/parking-lots/99999/vehicles/SOME-PLATE');

        $response->assertStatus(404);
    }

    public function test_van_is_rejected_when_three_free_spots_cross_a_row_boundary(): void
    {
        $parkingId = $this->createDummyParking();
        $sectionA = Section::where('parking_lot_id', $this->lotId)
            ->where('name', 'A')
            ->first();

        // Occupy all car spots, then free three that do NOT share a row:
        // (row 2, col 4), (row 2, col 5), (row 3, col 1).
        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        Spot::where('section_id', $sectionA->id)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('grid_row', 2)->whereIn('grid_column', [4, 5]);
                })->orWhere(function ($q2) {
                    $q2->where('grid_row', 3)->where('grid_column', 1);
                });
            })
            ->update(['parking_id' => null]);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-CROSS',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(409);
    }

    public function test_van_is_rejected_when_aisle_splits_a_row(): void
    {
        $lot = ParkingLot::create(['name' => 'Sparse Lot']);
        $section = Section::create(['parking_lot_id' => $lot->id, 'name' => 'X']);

        $now = now();
        $rows = [];
        // Row 1, columns 1, 2, 4, 5 (column 3 is an implicit aisle).
        foreach ([1, 2, 4, 5] as $column) {
            $rows[] = [
                'parking_lot_id' => $lot->id,
                'section_id' => $section->id,
                'type' => SpotType::Car->value,
                'grid_row' => 1,
                'grid_column' => $column,
                'parking_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Spot::insert($rows);

        $response = $this->postJson("/api/parking-lots/{$lot->id}/sessions", [
            'license_plate' => 'VAN-AISLE',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(409);
    }

    // -----------------------------------------------------------------
    // Group 3: Validation Errors
    // -----------------------------------------------------------------

    public function test_park_fails_without_license_plate(): void
    {
        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'vehicle_type' => VehicleType::Car->value,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('license_plate');
    }

    public function test_park_fails_with_invalid_vehicle_type(): void
    {
        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'BAD-TYPE',
            'vehicle_type' => 'spaceship',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_type');
    }

    public function test_park_fails_when_lot_does_not_exist(): void
    {
        // parking_lot_id is now a URL segment; a non-existent lot returns 404.
        $response = $this->postJson('/api/parking-lots/99999/sessions', [
            'license_plate' => 'NO-LOT',
            'vehicle_type' => VehicleType::Car->value,
        ]);

        $response->assertStatus(404);
    }

    public function test_park_fails_with_vehicle_type_mismatch(): void
    {
        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'MISMATCH',
            'vehicle_type' => VehicleType::Car->value,
        ])->assertStatus(201);

        $this->deleteJson("/api/parking-lots/{$this->lotId}/vehicles/MISMATCH");

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'MISMATCH',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Vehicle type does not match the existing record.',
                'recorded_type' => VehicleType::Car->value,
                'requested_type' => VehicleType::Van->value,
            ]);
    }

    public function test_same_plate_can_park_at_two_different_lots(): void
    {
        $lotB = ParkingLot::create(['name' => 'Second Lot']);
        $sectionB = Section::create(['parking_lot_id' => $lotB->id, 'name' => 'B1']);

        $spotRows = [];
        $now = now();
        // One row of 10 car spots so a van can also park here.
        for ($column = 1; $column <= 10; $column++) {
            $spotRows[] = [
                'parking_lot_id' => $lotB->id,
                'section_id' => $sectionB->id,
                'type' => SpotType::Car->value,
                'grid_row' => 1,
                'grid_column' => $column,
                'parking_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Spot::insert($spotRows);

        $insertedSpotsB = Spot::where('section_id', $sectionB->id)
            ->orderBy('grid_column')
            ->get(['id', 'parking_lot_id', 'section_id', 'grid_row', 'grid_column']);
        $windowRowsB = [];
        for ($i = 0; $i + 2 < $insertedSpotsB->count(); $i++) {
            $l = $insertedSpotsB[$i];
            $m = $insertedSpotsB[$i + 1];
            $r = $insertedSpotsB[$i + 2];
            $windowRowsB[] = [
                'parking_lot_id' => $l->parking_lot_id,
                'section_id' => $l->section_id,
                'grid_row' => $l->grid_row,
                'start_column' => $l->grid_column,
                'car_spot_left_id' => $l->id,
                'car_spot_mid_id' => $m->id,
                'car_spot_right_id' => $r->id,
                'parking_id' => null,
                'blocked_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        VanWindow::insert($windowRowsB);

        $plate = 'DUPLICATE-PLATE-1';

        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => $plate,
            'vehicle_type' => VehicleType::Car->value,
        ])->assertStatus(201);

        $this->postJson("/api/parking-lots/{$lotB->id}/sessions", [
            'license_plate' => $plate,
            'vehicle_type' => VehicleType::Van->value,
        ])->assertStatus(201);

        $this->assertSame(2, Vehicle::where('license_plate', $plate)->count());
    }

    public function test_park_response_includes_grid_coordinates(): void
    {
        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'GRID-001',
            'vehicle_type' => VehicleType::Car->value,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id', 'license_plate', 'vehicle_type', 'parking_lot_id', 'started_at',
                'spots' => [
                    '*' => ['id', 'type', 'section_id', 'grid_row', 'grid_column'],
                ],
            ])
            ->assertJsonMissingPath('spots.0.position');

        $this->assertIsInt($response->json('spots.0.grid_row'));
        $this->assertIsInt($response->json('spots.0.grid_column'));
    }

    // -----------------------------------------------------------------
    // Group 4: State & Availability
    // -----------------------------------------------------------------

    public function test_availability_reflects_total_capacity(): void
    {
        $expected = Spot::where('parking_lot_id', $this->lotId)->count();

        $response = $this->getJson("/api/parking-lots/{$this->lotId}/availability");

        $response->assertStatus(200)
            ->assertJsonPath('total_capacity', $expected);
    }

    public function test_availability_reflects_occupied_spots(): void
    {
        $totalCarSpots = Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->count();

        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'CAR-AVAIL',
            'vehicle_type' => VehicleType::Car->value,
        ]);

        $response = $this->getJson("/api/parking-lots/{$this->lotId}/availability");

        $response->assertStatus(200)
            ->assertJsonPath('available_car_spots', $totalCarSpots - 1);
    }

    public function test_van_occupancy_reduces_available_van_spaces(): void
    {
        $before = $this->getJson("/api/parking-lots/{$this->lotId}/availability")
            ->json('available_van_spaces');

        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-AVAIL',
            'vehicle_type' => VehicleType::Van->value,
        ])->assertStatus(201);

        $after = $this->getJson("/api/parking-lots/{$this->lotId}/availability")
            ->json('available_van_spaces');

        $this->assertSame($before - 1, $after);
    }

    public function test_parking_lot_is_multi_tenant(): void
    {
        $lot2 = ParkingLot::create(['name' => 'Second Lot']);
        $section2 = Section::create(['parking_lot_id' => $lot2->id, 'name' => 'C']);

        $spotRows = [];
        $now = now();
        for ($column = 1; $column <= 5; $column++) {
            $spotRows[] = [
                'parking_lot_id' => $lot2->id,
                'section_id' => $section2->id,
                'type' => SpotType::Motorcycle->value,
                'grid_row' => 1,
                'grid_column' => $column,
                'parking_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Spot::insert($spotRows);

        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'MULTI-001',
            'vehicle_type' => VehicleType::Car->value,
        ])->assertStatus(201);

        $response = $this->getJson("/api/parking-lots/{$lot2->id}/availability");

        $response->assertStatus(200)
            ->assertJsonPath('total_capacity', 5)
            ->assertJsonPath('available_motorcycle_spots', 5)
            ->assertJsonPath('available_car_spots', 0);
    }

    public function test_unpark_fails_when_vehicle_is_parked_in_different_lot(): void
    {
        $lot2 = ParkingLot::create(['name' => 'Other Lot']);
        $section2 = Section::create([
            'parking_lot_id' => $lot2->id,
            'name' => 'C',
        ]);

        $spotRows = [];
        $now = now();
        for ($column = 1; $column <= 5; $column++) {
            $spotRows[] = [
                'parking_lot_id' => $lot2->id,
                'section_id' => $section2->id,
                'type' => SpotType::Car->value,
                'grid_row' => 1,
                'grid_column' => $column,
                'parking_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Spot::insert($spotRows);

        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'WRONG-LOT',
            'vehicle_type' => VehicleType::Car->value,
        ])->assertStatus(201);

        $response = $this->deleteJson("/api/parking-lots/{$lot2->id}/vehicles/WRONG-LOT");

        $response->assertStatus(404);
    }

    public function test_van_parks_at_section_boundary_with_exactly_three_consecutive_spots(): void
    {
        $parkingId = $this->createDummyParking();

        $sectionA = Section::where('parking_lot_id', $this->lotId)
            ->where('name', 'A')
            ->first();

        // Occupy every car spot, then free exactly one window of 3 in row 2 of A.
        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        Spot::where('section_id', $sectionA->id)
            ->where('grid_row', 2)
            ->whereIn('grid_column', [1, 2, 3])
            ->update(['parking_id' => null]);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-EDGE',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $newParkingId = $response->json('id');
        $spots = Spot::where('parking_id', $newParkingId)
            ->orderBy('grid_column')
            ->get();

        $this->assertCount(3, $spots);
        $this->assertSame(2, $spots[0]->grid_row);
        $this->assertSame(1, $spots[0]->grid_column);
        $this->assertSame(2, $spots[1]->grid_column);
        $this->assertSame(3, $spots[2]->grid_column);
        $this->assertSame($sectionA->id, $spots[0]->section_id);
    }

    public function test_park_fails_when_all_spots_are_full(): void
    {
        $parkingId = $this->createDummyParking();

        Spot::where('parking_lot_id', $this->lotId)
            ->whereNull('parking_id')
            ->update(['parking_id' => $parkingId]);

        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'FULL-MOTO',
            'vehicle_type' => VehicleType::Motorcycle->value,
        ])->assertStatus(409);

        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'FULL-CAR',
            'vehicle_type' => VehicleType::Car->value,
        ])->assertStatus(409);

        $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'FULL-VAN',
            'vehicle_type' => VehicleType::Van->value,
        ])->assertStatus(409);
    }

    public function test_availability_returns_404_for_nonexistent_lot(): void
    {
        $this->getJson('/api/parking-lots/99999/availability')
            ->assertStatus(404);
    }

    public function test_availability_does_not_count_vertical_adjacency_as_van_slot(): void
    {
        $lot = ParkingLot::create(['name' => 'Vertical Test Lot']);
        $section = Section::create(['parking_lot_id' => $lot->id, 'name' => 'V']);

        // Three free car spots, but their layout has NO horizontal run of 3:
        //   (row 2, col 1), (row 3, col 1), (row 3, col 2)
        // Vertical adjacency must NOT count.
        $now = now();
        Spot::insert([
            [
                'parking_lot_id' => $lot->id, 'section_id' => $section->id,
                'type' => SpotType::Car->value, 'grid_row' => 2, 'grid_column' => 1,
                'parking_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'parking_lot_id' => $lot->id, 'section_id' => $section->id,
                'type' => SpotType::Car->value, 'grid_row' => 3, 'grid_column' => 1,
                'parking_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'parking_lot_id' => $lot->id, 'section_id' => $section->id,
                'type' => SpotType::Car->value, 'grid_row' => 3, 'grid_column' => 2,
                'parking_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $response = $this->getJson("/api/parking-lots/{$lot->id}/availability");

        $response->assertStatus(200)
            ->assertJsonPath('available_van_spaces', 0)
            ->assertJsonPath('available_car_spots', 3);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createDummyParking(): int
    {
        $vehicle = Vehicle::create([
            'parking_lot_id' => $this->lotId,
            'license_plate' => 'DUMMY-'.uniqid(),
            'type' => VehicleType::Car->value,
        ]);
        $parking = ParkingSession::create([
            'parking_lot_id' => $this->lotId,
            'vehicle_id' => $vehicle->id,
            'started_at' => now(),
        ]);

        return $parking->id;
    }

    private function occupyAllMotorcycleSpots(int $parkingLotId): void
    {
        $parkingId = $this->createDummyParking();
        Spot::where('parking_lot_id', $parkingLotId)
            ->where('type', SpotType::Motorcycle->value)
            ->update(['parking_id' => $parkingId]);
    }

    private function occupyAllCarSpots(int $parkingLotId): void
    {
        $parkingId = $this->createDummyParking();
        Spot::where('parking_lot_id', $parkingLotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);
    }

    // -----------------------------------------------------------------
    // Group A: Seeder integrity
    // -----------------------------------------------------------------

    public function test_seeder_creates_expected_van_windows_count(): void
    {
        // Fresh seed already ran via RefreshDatabase. Verify materialization.
        $count = \App\Domains\Parking\Models\VanWindow::count();
        $this->assertSame(12, $count, '4 car rows × 3 windows each = 12 van_windows');

        $allFree = \App\Domains\Parking\Models\VanWindow::whereNull('parking_id')
            ->where('blocked_count', 0)
            ->count();
        $this->assertSame(12, $allFree, 'All windows start with parking_id NULL and blocked_count 0');
    }

    public function test_seeder_van_windows_reference_correct_car_spots(): void
    {
        $windows = \App\Domains\Parking\Models\VanWindow::with(['carSpotLeft', 'carSpotMid', 'carSpotRight'])
            ->get();

        foreach ($windows as $w) {
            $this->assertSame($w->section_id, $w->carSpotLeft->section_id);
            $this->assertSame($w->section_id, $w->carSpotMid->section_id);
            $this->assertSame($w->section_id, $w->carSpotRight->section_id);

            $this->assertSame($w->grid_row, $w->carSpotLeft->grid_row);
            $this->assertSame($w->grid_row, $w->carSpotMid->grid_row);
            $this->assertSame($w->grid_row, $w->carSpotRight->grid_row);

            $this->assertSame($w->start_column,     $w->carSpotLeft->grid_column);
            $this->assertSame($w->start_column + 1, $w->carSpotMid->grid_column);
            $this->assertSame($w->start_column + 2, $w->carSpotRight->grid_column);

            $this->assertSame(\App\Domains\Parking\Data\SpotType::Car, $w->carSpotLeft->type);
            $this->assertSame(\App\Domains\Parking\Data\SpotType::Car, $w->carSpotMid->type);
            $this->assertSame(\App\Domains\Parking\Data\SpotType::Car, $w->carSpotRight->type);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $before = \App\Domains\Parking\Models\VanWindow::count();
        Artisan::call('db:seed');
        $after = \App\Domains\Parking\Models\VanWindow::count();

        $this->assertSame($before, $after, 'db:seed twice must not duplicate van_windows rows');
    }

    // -----------------------------------------------------------------
    // Group B: blocked_count invariant
    // -----------------------------------------------------------------

    /**
     * Asserts the blocked_count invariant for every van_windows row:
     *
     *   W.blocked_count == count(s in W's 3 underlying car spots:
     *                            s.parking_id IS NOT NULL
     *                            AND s.parking_id !== W.parking_id)
     *
     * When W is unoccupied (W.parking_id NULL), this collapses to "count of
     * W's spots that are taken." When W is occupied by a van, all 3 spots
     * carry that van's session id and the count is 0.
     */
    private function assertBlockedCountInvariant(): void
    {
        $windows = \App\Domains\Parking\Models\VanWindow::with([
            'carSpotLeft', 'carSpotMid', 'carSpotRight',
        ])->get();

        foreach ($windows as $w) {
            $expected = 0;
            foreach ([$w->carSpotLeft, $w->carSpotMid, $w->carSpotRight] as $spot) {
                if ($spot->parking_id !== null && $spot->parking_id !== $w->parking_id) {
                    $expected++;
                }
            }
            $this->assertSame(
                $expected,
                $w->blocked_count,
                "Window id={$w->id} (section={$w->section_id}, row={$w->grid_row}, start_col={$w->start_column}): expected blocked_count={$expected}, got {$w->blocked_count}"
            );
        }
    }

    public function test_blocked_count_invariant_holds_after_arbitrary_park_unpark_sequence(): void
    {
        // Fixed-seed scenario for reproducibility.
        $lot = \App\Domains\Parking\Models\ParkingLot::first();
        $base = "/api/parking-lots/{$lot->id}";

        $operations = [
            ['park', 'car',        'CAR-001'],
            ['park', 'van',        'VAN-001'],
            ['park', 'motorcycle', 'MC-001'],
            ['park', 'car',        'CAR-002'],
            ['park', 'van',        'VAN-002'],
            ['unpark', null,       'VAN-001'],
            ['park', 'car',        'CAR-003'],
            ['unpark', null,       'CAR-002'],
            ['park', 'van',        'VAN-003'],
            ['unpark', null,       'VAN-002'],
        ];

        foreach ($operations as [$op, $type, $plate]) {
            if ($op === 'park') {
                $this->postJson("{$base}/sessions", [
                    'license_plate' => $plate,
                    'vehicle_type' => $type,
                ])->assertStatus(201);
            } else {
                $this->deleteJson("{$base}/vehicles/{$plate}")->assertStatus(204);
            }
            $this->assertBlockedCountInvariant();
        }
    }

    public function test_motorcycle_fallback_to_car_spot_bumps_blocked_count(): void
    {
        $lot = \App\Domains\Parking\Models\ParkingLot::first();
        $base = "/api/parking-lots/{$lot->id}";

        // Fill all 10 motorcycle spots.
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson("{$base}/sessions", [
                'license_plate' => "MC-{$i}",
                'vehicle_type' => 'motorcycle',
            ])->assertStatus(201);
        }

        // Snapshot baseline blocked_count map.
        $baseline = \App\Domains\Parking\Models\VanWindow::query()
            ->pluck('blocked_count', 'id')->toArray();

        // 11th motorcycle should fall back to a car spot.
        $response = $this->postJson("{$base}/sessions", [
            'license_plate' => 'MC-11',
            'vehicle_type' => 'motorcycle',
        ])->assertStatus(201);

        $fallbackSpot = $response->json('spots.0');
        $this->assertSame('car', $fallbackSpot['type'], 'motorcycle should fall back to a car spot');

        $after = \App\Domains\Parking\Models\VanWindow::query()
            ->pluck('blocked_count', 'id')->toArray();

        // At least one overlapping window must have blocked_count incremented.
        // Up to 3 windows include this spot.
        $diff = 0;
        foreach ($after as $id => $bc) {
            if ($bc > ($baseline[$id] ?? 0)) {
                $diff++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $diff, 'fallback to a car spot should bump at least 1 overlapping van_window');
        $this->assertLessThanOrEqual(3, $diff, 'a single car spot belongs to at most 3 van_windows');

        $this->assertBlockedCountInvariant();
    }

    public function test_unpark_van_resets_blocked_count(): void
    {
        $lot = \App\Domains\Parking\Models\ParkingLot::first();
        $base = "/api/parking-lots/{$lot->id}";

        $baselineMap = \App\Domains\Parking\Models\VanWindow::query()
            ->pluck('blocked_count', 'id')->toArray();

        $this->postJson("{$base}/sessions", [
            'license_plate' => 'VAN-RESET',
            'vehicle_type' => 'van',
        ])->assertStatus(201);

        $this->deleteJson("{$base}/vehicles/VAN-RESET")->assertStatus(204);

        $finalMap = \App\Domains\Parking\Models\VanWindow::query()
            ->pluck('blocked_count', 'id')->toArray();
        foreach ($baselineMap as $id => $expected) {
            $this->assertSame($expected, $finalMap[$id] ?? null, "Window {$id} blocked_count should return to baseline");
        }

        $this->assertBlockedCountInvariant();

        // The van's window itself (now unknown which one) — all should be free.
        $occupied = \App\Domains\Parking\Models\VanWindow::whereNotNull('parking_id')->count();
        $this->assertSame(0, $occupied);
    }

    public function test_van_park_leaves_own_window_blocked_count_zero(): void
    {
        $lot = \App\Domains\Parking\Models\ParkingLot::first();
        $base = "/api/parking-lots/{$lot->id}";

        $this->postJson("{$base}/sessions", [
            'license_plate' => 'VAN-OWN',
            'vehicle_type' => 'van',
        ])->assertStatus(201);

        // The window the van just took has parking_id non-null AND blocked_count = 0.
        $occupiedWindow = \App\Domains\Parking\Models\VanWindow::whereNotNull('parking_id')->first();
        $this->assertNotNull($occupiedWindow);
        $this->assertSame(0, $occupiedWindow->blocked_count, 'A van does not block its own window');

        $this->assertBlockedCountInvariant();
    }

    // -----------------------------------------------------------------
    // Group C: Allocator on the new query path
    // -----------------------------------------------------------------

    public function test_van_skips_window_with_blocked_underlying_spot(): void
    {
        $lot = \App\Domains\Parking\Models\ParkingLot::first();
        $base = "/api/parking-lots/{$lot->id}";

        // Park a car at section A, row 2, column 3 (middle of section A row 2).
        // This blocks ALL windows in section A row 2 (start_column 1, 2, 3 each
        // include column 3 in their 3-spot span). So the van must pick from
        // section A row 3, or section B.
        $sectionA = \App\Domains\Parking\Models\Section::where('name', 'A')->first();
        $targetSpot = \App\Domains\Parking\Models\Spot::query()
            ->where('section_id', $sectionA->id)
            ->where('grid_row', 2)
            ->where('grid_column', 3)
            ->where('type', 'car')
            ->first();

        // Directly mark this spot as taken (no need to go through the API).
        $parking = \App\Domains\Parking\Models\ParkingSession::create([
            'parking_lot_id' => $lot->id,
            'vehicle_id' => \App\Domains\Parking\Models\Vehicle::create([
                'parking_lot_id' => $lot->id,
                'license_plate' => 'PRE-BLOCK',
                'type' => 'car',
            ])->id,
            'started_at' => now(),
        ]);
        $targetSpot->update(['parking_id' => $parking->id]);
        app(\App\Domains\Parking\Services\SpotAllocator::class)
            ->bumpBlockedCountForCarSpot(
                sectionId: $targetSpot->section_id,
                gridRow: $targetSpot->grid_row,
                gridColumn: $targetSpot->grid_column,
            );

        // Now request a van; should NOT use any window in section A row 2.
        $response = $this->postJson("{$base}/sessions", [
            'license_plate' => 'VAN-SKIP',
            'vehicle_type' => 'van',
        ])->assertStatus(201);

        $spotsTaken = collect($response->json('spots'));
        $notInBlockedRow = $spotsTaken->every(
            fn ($s) => ! ($s['section_id'] === $sectionA->id && $s['grid_row'] === 2)
        );
        $this->assertTrue($notInBlockedRow, 'Van must avoid section A row 2 entirely');
    }

    public function test_van_returns_409_when_all_windows_blocked(): void
    {
        $lot = \App\Domains\Parking\Models\ParkingLot::first();
        $base = "/api/parking-lots/{$lot->id}";

        // Pre-occupy column 3 of every car row in both sections — that's 4
        // car spots, blocking every van window in the lot.
        $allocator = app(\App\Domains\Parking\Services\SpotAllocator::class);
        foreach (\App\Domains\Parking\Models\Section::all() as $section) {
            foreach ([2, 3] as $row) {
                $spot = \App\Domains\Parking\Models\Spot::query()
                    ->where('section_id', $section->id)
                    ->where('grid_row', $row)
                    ->where('grid_column', 3)
                    ->first();
                $parking = \App\Domains\Parking\Models\ParkingSession::create([
                    'parking_lot_id' => $lot->id,
                    'vehicle_id' => \App\Domains\Parking\Models\Vehicle::create([
                        'parking_lot_id' => $lot->id,
                        'license_plate' => "BLOCK-{$section->id}-{$row}",
                        'type' => 'car',
                    ])->id,
                    'started_at' => now(),
                ]);
                $spot->update(['parking_id' => $parking->id]);
                $allocator->bumpBlockedCountForCarSpot(
                    sectionId: $spot->section_id,
                    gridRow: $spot->grid_row,
                    gridColumn: $spot->grid_column,
                );
            }
        }

        $this->postJson("{$base}/sessions", [
            'license_plate' => 'VAN-FAIL',
            'vehicle_type' => 'van',
        ])->assertStatus(409);
    }

    /**
     * Independent greedy non-overlapping van count, computed in PHP from raw
     * spots state. Used to cross-check countAvailableVanSpaces.
     */
    private function independentGreedyVanCount(int $parkingLotId): int
    {
        $spots = \App\Domains\Parking\Models\Spot::query()
            ->where('parking_lot_id', $parkingLotId)
            ->where('type', 'car')
            ->orderBy('section_id')->orderBy('grid_row')->orderBy('grid_column')
            ->get(['section_id', 'grid_row', 'grid_column', 'parking_id']);

        $byRow = $spots->groupBy(fn ($s) => "{$s->section_id}:{$s->grid_row}");
        $total = 0;

        foreach ($byRow as $rowSpots) {
            $rowSpots = $rowSpots->values();
            $run = 0;
            $prevCol = null;

            foreach ($rowSpots as $s) {
                $colOk = $prevCol === null || $s->grid_column === $prevCol + 1;
                if ($s->parking_id === null && $colOk) {
                    $run++;
                    if ($run === 3) {
                        $total++;
                        $run = 0; // claim and reset; non-overlapping packing
                        $prevCol = null;
                        continue;
                    }
                } else {
                    $run = $s->parking_id === null ? 1 : 0;
                }
                $prevCol = $s->grid_column;
            }
        }
        return $total;
    }

    public function test_available_van_spaces_matches_independent_greedy_after_sequence(): void
    {
        $lot = \App\Domains\Parking\Models\ParkingLot::first();
        $base = "/api/parking-lots/{$lot->id}";

        $sequence = [
            ['park', 'car',        'CAR-G1'],
            ['park', 'van',        'VAN-G1'],
            ['park', 'motorcycle', 'MC-G1'],
            ['park', 'car',        'CAR-G2'],
            ['unpark', null,       'VAN-G1'],
            ['park', 'van',        'VAN-G2'],
        ];

        foreach ($sequence as [$op, $type, $plate]) {
            if ($op === 'park') {
                $this->postJson("{$base}/sessions", [
                    'license_plate' => $plate,
                    'vehicle_type' => $type,
                ])->assertStatus(201);
            } else {
                $this->deleteJson("{$base}/vehicles/{$plate}")->assertStatus(204);
            }

            $apiCount = $this->getJson("{$base}/availability")
                ->json('available_van_spaces');
            $expected = $this->independentGreedyVanCount($lot->id);
            $this->assertSame(
                $expected,
                $apiCount,
                "Mismatch after op={$op} plate={$plate}: greedy={$expected}, api={$apiCount}"
            );
        }
    }
}
