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

class ParkingApiTest extends TestCase
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

        $positions = $spots->pluck('position')->sort()->values();
        $this->assertSame($positions[0] + 1, $positions[1]);
        $this->assertSame($positions[1] + 1, $positions[2]);
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

        $response->assertStatus(409);
        $this->assertDatabaseCount('parkings', 1);
    }

    public function test_van_is_rejected_when_car_spots_are_fragmented(): void
    {
        $parkingId = $this->createDummyParking();
        $sections = Section::where('parking_lot_id', $this->lotId)->get();

        // Fragment car spots so no 3 consecutive spots remain in any section.
        // Free adjacent pairs (6-7, 9-10, 12-13) plus an isolated 15: each
        // section has free runs of length 2, 2, 2, 1 — exercising the trickier
        // "two-adjacent but not three" gap-detection edge.
        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        foreach ($sections as $section) {
            $this->freeSpotsAtPositions($section, [6, 7, 9, 10, 12, 13, 15]);
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
        $keepFreeIds = Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->limit(2)
            ->pluck('id');

        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->whereNotIn('id', $keepFreeIds)
            ->update(['parking_id' => $parkingId]);

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

    public function test_unpark_fails_for_unknown_vehicle_in_lot(): void
    {
        // license_plate is a URL segment; a plate never seen in this lot returns 404.
        $response = $this->deleteJson("/api/parking-lots/{$this->lotId}/vehicles/UNKNOWN-PLATE-XYZ");

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

        for ($position = 1; $position <= 10; $position++) {
            Spot::create([
                'parking_lot_id' => $lotB->id,
                'section_id' => $sectionB->id,
                'type' => SpotType::Car->value,
                'position' => $position,
            ]);
        }

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

        for ($position = 1; $position <= 5; $position++) {
            Spot::create([
                'parking_lot_id' => $lot2->id,
                'section_id' => $section2->id,
                'type' => SpotType::Motorcycle->value,
                'position' => $position,
            ]);
        }

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

        for ($position = 1; $position <= 5; $position++) {
            Spot::create([
                'parking_lot_id' => $lot2->id,
                'section_id' => $section2->id,
                'type' => SpotType::Car->value,
                'position' => $position,
            ]);
        }

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

        Spot::where('parking_lot_id', $this->lotId)
            ->where('type', SpotType::Car->value)
            ->update(['parking_id' => $parkingId]);

        Spot::where('section_id', $sectionA->id)
            ->whereIn('position', [6, 7, 8])
            ->update(['parking_id' => null]);

        $response = $this->postJson("/api/parking-lots/{$this->lotId}/sessions", [
            'license_plate' => 'VAN-EDGE',
            'vehicle_type' => VehicleType::Van->value,
        ]);

        $response->assertStatus(201);

        $newParkingId = $response->json('id');
        $spots = Spot::where('parking_id', $newParkingId)
            ->orderBy('position')
            ->get();

        $this->assertCount(3, $spots);
        $this->assertSame(6, $spots[0]->position);
        $this->assertSame(7, $spots[1]->position);
        $this->assertSame(8, $spots[2]->position);
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

    private function freeSpotsAtPositions(Section $section, array $positions): void
    {
        Spot::query()
            ->where('section_id', $section->id)
            ->whereIn('position', $positions)
            ->update(['parking_id' => null]);
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
