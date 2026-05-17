<?php

namespace Tests\Feature;

use App\Domains\Parking\Data\SpotType;
use App\Domains\Parking\Data\VehicleType;
use App\Domains\Parking\Models\ParkingLot;
use App\Domains\Parking\Models\ParkingSession;
use App\Domains\Parking\Models\Section;
use App\Domains\Parking\Models\Spot;
use App\Domains\Parking\Models\Vehicle;
use Database\Seeders\ParkingLotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
