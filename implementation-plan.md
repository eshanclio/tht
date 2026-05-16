# Implementation Plan: Parking Lot Management System

## Architecture: Pragmatic DDD for Laravel

Domain-first grouping with clear layer separation, using Laravel 13, PostgreSQL, and Eloquent ORM natively. Actions as single-purpose service classes (AaaS pattern). Eloquent directly — no repository interfaces unless needed.

---

## 1. Directory Structure

```
src/
├── app/
│   ├── Domains/
│   │   └── Parking/
│   │       ├── Actions/              # Single-purpose use cases (AaaS pattern)
│   │       │   ├── ParkVehicle.php
│   │       │   ├── UnparkVehicle.php
│   │       │   └── GetLotAvailability.php
│   │       ├── Data/                 # DTOs + Enums + Value Objects (co-located)
│   │       │   ├── ParkVehicleData.php
│   │       │   ├── UnparkVehicleData.php
│   │       │   ├── LotAvailabilityData.php
│   │       │   ├── SpotType.php      # Enum: motorcycle, car
│   │       │   └── VehicleType.php   # Enum: motorcycle, car, van
│   │       ├── Models/               # Eloquent models (Laravel-native)
│   │       │   ├── ParkingLot.php
│   │       │   ├── Section.php
│   │       │   ├── Spot.php
│   │       │   ├── Vehicle.php
│   │       │   └── Parking.php
│   │       ├── Services/             # Pure domain logic (spot allocation + van availability)
│   │       │   └── SpotAllocator.php
│   │       └── Exceptions/           # Domain exceptions (self-rendering)
│   │           ├── NoAvailableSpotException.php
│   │           ├── VehicleAlreadyParkedException.php
│   │           └── VehicleNotParkedException.php
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── ParkingController.php
│   │   ├── Requests/
│   │   │   ├── ParkVehicleRequest.php
│   │   │   └── UnparkVehicleRequest.php
│   │   └── Resources/
│   │       ├── ParkingResource.php
│   │       └── AvailabilityResource.php
│   │
│   └── Providers/
│       └── AppServiceProvider.php  # Model::shouldBeStrict() in boot()
│
├── database/
│   ├── migrations/
│   │   └── 2026_05_13_000001_create_parking_schema.php
│   └── seeders/
│       └── ParkingLotSeeder.php
│
├── routes/
│   └── api.php
│
└── tests/
    └── Feature/
        └── ParkingApiTest.php
```

---

## 2. Database Schema & Migrations

Migrations use `foreignId()->constrained()`, `cascadeOnDelete()`, `string` columns (not PostgreSQL enum types) with PHP Enum casting on models, composite unique constraints, and **PostgreSQL partial indexes** (via `DB::statement()`) for hot-path allocation queries.

> **PostgreSQL FK index note:** Unlike MySQL/InnoDB, PostgreSQL does **not** auto-create indexes on FK columns. `foreignId()->constrained()` only creates the FK constraint. We must add explicit indexes on FK columns used in queries (e.g., `spots.parking_lot_id`). The unique composite `(section_id, position)` covers `section_id` queries as the leading column, so no standalone `section_id` index is needed.

### Combined Migration

All tables are created in a single migration file to respect dependency order (`parkings` must exist before `spots.parking_id` can reference it):

```php
Schema::create("parking_lots", function (Blueprint $table) {
	$table->id();
	$table->string("name");
	$table->timestamps();
});

Schema::create("sections", function (Blueprint $table) {
	$table->id();
	$table->foreignId("parking_lot_id")
		->constrained()
		->cascadeOnDelete();
	$table->string("name");
	$table->timestamps();

	$table->index("parking_lot_id");
});

Schema::create("spots", function (Blueprint $table) {
	$table->id();
	$table->foreignId("parking_lot_id")
		->constrained()
		->cascadeOnDelete();
	$table->foreignId("section_id")
		->constrained()
		->cascadeOnDelete();
	$table->string("type");
	$table->integer("position");
	// parking_id is a deferred FK — added after parkings table is created.
	$table->foreignId("parking_id")->nullable();
	$table->timestamps();

	$table->unique(["section_id", "position"]);
	$table->index("parking_lot_id");
});

Schema::create("vehicles", function (Blueprint $table) {
	$table->id();
	$table->string("license_plate")->unique();
	$table->string("type");
	$table->timestamps();
});

Schema::create("parkings", function (Blueprint $table) {
	$table->id();
	$table->foreignId("parking_lot_id")
		->constrained()
		->cascadeOnDelete();
	$table->foreignId("vehicle_id")
		->constrained()
		->cascadeOnDelete();
	$table->timestamp("started_at");
	$table->timestamp("ended_at")->nullable();
});

// Deferred FK: spots.parking_id references parkings.id.
// Cannot be declared inside Schema::create("spots") because
// parkings does not yet exist at that point.
Schema::table("spots", function (Blueprint $table) {
	$table->foreign("parking_id")
		->references("id")
		->on("parkings")
		->nullOnDelete();
});

// PostgreSQL partial indexes — only index rows that matter for allocation.
// These are smaller and faster than full composite indexes because they
// exclude occupied spots and irrelevant types.
DB::statement(
	"CREATE INDEX spots_available_car_section_idx ON spots (section_id, position)"
	." WHERE type = 'car' AND parking_id IS NULL"
);
DB::statement(
	"CREATE INDEX spots_available_motorcycle_lot_idx ON spots (parking_lot_id)"
	." WHERE type = 'motorcycle' AND parking_id IS NULL"
);
DB::statement(
	"CREATE INDEX spots_available_car_lot_idx ON spots (parking_lot_id)"
	." WHERE type = 'car' AND parking_id IS NULL"
);
DB::statement(
	"CREATE INDEX parkings_active_idx ON parkings (vehicle_id, parking_lot_id)"
	." WHERE ended_at IS NULL"
);
```

> **No `parking_spot` pivot table.** The many-to-many relationship between Parking and Spot is replaced by a `parking_id` nullable FK on `spots`. This eliminates an entire table, removes the `is_occupied` denormalized column (occupancy is derived from `parking_id IS NULL`), and simplifies all park/unpark operations. The Parking→Spot relationship changes from `BelongsToMany` to `HasMany`.

---

## 3. Eloquent Models & Relationships

### `ParkingLot`
```php
class ParkingLot extends Model
{
	protected $fillable = ["name"];

	public function sections(): HasMany
	public function parkings(): HasMany
}
```

### `Section`
```php
class Section extends Model
{
	protected $fillable = ["parking_lot_id", "name"];

	public function parkingLot(): BelongsTo
	public function spots(): HasMany
}
```

### `Spot`
```php
class Spot extends Model
{
	protected $fillable = ["parking_lot_id", "section_id", "type", "position", "parking_id"];

	protected function casts(): array
	{
		return [
			"type" => SpotType::class,
			"parking_id" => "integer", // nullable FK — null means available
		];
	}

	public function parkingLot(): BelongsTo
	public function section(): BelongsTo
	public function parking(): BelongsTo // the active parking occupying this spot (null if available)
}
```

### `Vehicle`
```php
class Vehicle extends Model
{
	protected $fillable = ["license_plate", "type"];

	protected function casts(): array
	{
		return [
			"type" => VehicleType::class,
		];
	}

	public function parkings(): HasMany
}
```

### `Parking`
```php
class Parking extends Model
{
	public $timestamps = false; // Uses started_at/ended_at instead of created_at/updated_at

	protected $fillable = ["parking_lot_id", "vehicle_id", "started_at", "ended_at"];

	protected function casts(): array
	{
		return [
			"started_at" => "datetime",
			"ended_at" => "datetime",
		];
	}

	public function parkingLot(): BelongsTo
	public function vehicle(): BelongsTo
	public function spots(): HasMany // HasMany (not BelongsToMany) — parking_id FK is on spots
}
```

---

## 4. Spot Allocation Algorithm

All queries use Eloquent ORM. `lockForUpdate()` inside `DB::transaction()` ensures atomicity — PostgreSQL blocks concurrent transactions until the lock is released, then re-evaluates the WHERE clause against committed data.

### Motorcycle Allocation

```php
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

return $spot ? [$spot->id] : throw new NoAvailableSpotException();
```

### Car Allocation

```php
$spot = Spot::where("parking_lot_id", $parkingLotId)
	->where("type", SpotType::Car->value)
	->whereNull("parking_id")
	->lockForUpdate()
	->first();

return $spot ? [$spot->id] : throw new NoAvailableSpotException();
```

### Van Allocation (3 Consecutive Car Spots in Same Section)

Single query locks all available car spots in the lot, then PHP scans for consecutive positions grouped by section. No Section model loading needed — we group by `section_id` in PHP.

```php
// Single query: lock all available car spots in the lot, ordered for scanning
$spots = Spot::where("parking_lot_id", $parkingLotId)
	->where("type", SpotType::Car->value)
	->whereNull("parking_id")
	->orderBy("section_id")
	->orderBy("position")
	->lockForUpdate()
	->get();

// Group by section_id in PHP (no Section model needed)
$grouped = $spots->groupBy("section_id");

foreach ($grouped as $sectionId => $sectionSpots) {
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
```

**Concurrency note:** `lockForUpdate()` on the Spot query locks all available car spot rows atomically. No Section model loading needed — sections are static/seeded and we only need `section_id` for grouping, which is already on the Spot model. The `ParkVehicle` action wraps the full flow in `DB::transaction()`.

**Query count:** 1 query. The partial index `spots_available_car_section_idx` covers this exact query pattern.

---

## 5. Actions (Application Layer)

### `ParkVehicle::handle(ParkVehicleData $data): Parking`

1. Find or create `Vehicle` by `license_plate` using **`createOrFirst()`** — race-condition safe (INSERT-first, SELECT-fallback on unique constraint violation). If the vehicle already exists and its stored `type` does not match the submitted `vehicle_type`, throw `ValidationException::withMessages(["vehicle_type" => ...])` (400).
2. Check if vehicle already has an active parking in any lot (`whereNull("ended_at")`). Uses partial index `parkings_active_idx`. If so, throw `VehicleAlreadyParkedException` (409).
3. Start `DB::transaction()`.
4. Call `SpotAllocator::allocate()` to get spot IDs (uses `lockForUpdate()` within the transaction).
5. Create `Parking` record with `started_at = now()`.
6. **Bulk update** `Spot::parking_id` for allocated spots: `Spot::whereIn("id", $spotIds)->update(["parking_id" => $parking->id])` — single UPDATE query, not N individual saves. This both marks spots as occupied AND establishes the HasMany relationship.
7. Load relationships: `$parking->load(["vehicle", "spots"])` — eager loading prevents N+1 in `ParkingResource`.
8. Commit transaction and return `Parking` model.

**Query count:** ~5–7 queries total (vehicle createOrFirst, active parking check, spot allocation [1–2 queries depending on type], parking insert, bulk spot update, eager load [2 queries]).

### `UnparkVehicle::handle(UnparkVehicleData $data): void`

1. Find `Vehicle` by `license_plate`. If not found, throw `VehicleNotParkedException` (404).
2. Start `DB::transaction()`.
3. Find active `Parking` for vehicle in lot (`whereNull("ended_at")`). Uses partial index `parkings_active_idx`. If not found, throw `VehicleNotParkedException` (404).
4. Set `ended_at = now()`.
5. **Bulk free** all associated spots: `Spot::where("parking_id", $parking->id)->update(["parking_id" => null])` — single UPDATE query. No eager-load of spots needed since we update directly by `parking_id`.
6. Commit transaction.

**Query count:** ~4 queries total (vehicle lookup, active parking find, parking update, bulk spot free).

**No eager-load needed for unpark** — we free spots by `parking_id` directly, no need to pluck spot IDs first. The `ended_at` field on `Parking` marks the session as inactive.

### `GetLotAvailability::handle(int $parkingLotId): LotAvailabilityData`

Uses **2 queries total** (down from N+1). All queries use Laravel's query builder — **no raw SQL**.

**Query 1 — Spot counts via PostgreSQL `FILTER` clause** (single query replaces 4+ separate `count()` queries):

```php
$stats = DB::table("spots")
	->where("parking_lot_id", $parkingLotId)
	->selectRaw("count(*) as total")
	->selectRaw("count(*) filter (where type = 'motorcycle') as total_motorcycle")
	->selectRaw("count(*) filter (where type = 'car') as total_car")
	->selectRaw("count(*) filter (where parking_id is null) as total_available")
	->selectRaw("count(*) filter (where type = 'motorcycle' and parking_id is null) as available_motorcycle")
	->selectRaw("count(*) filter (where type = 'car' and parking_id is null) as available_car")
	->first();
```

The `FILTER` clause is PostgreSQL-specific and **faster** than `CASE WHEN` conditional aggregates. It computes all 6 counts in a single table scan. Uses the `parking_lot_id` index.

**Query 2 — Van availability via `SpotAllocator::countAvailableVanSpaces()`** (reuses the same Eloquent + PHP gaps-and-islands algorithm as allocation):

```php
$availableVanSpaces = $spotAllocator->countAvailableVanSpaces($parkingLotId);
```

The `countAvailableVanSpaces` method lives in `SpotAllocator` — it reuses the same gaps-and-islands algorithm as `allocateVan`, but counts **all** non-overlapping van spaces instead of finding the first allocation:

```php
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
```

This uses `floor(run_length / 3)` to count non-overlapping van spaces per consecutive run. A run of 6 consecutive spots yields 2 van spaces; a run of 5 yields 1. The partial index `spots_available_car_section_idx` covers this query.

**Return:**

```php
return new LotAvailabilityData(
	totalMotorcycleSpots: (int) $stats->total_motorcycle,
	availableMotorcycleSpots: (int) $stats->available_motorcycle,
	totalCarSpots: (int) $stats->total_car,
	availableCarSpots: (int) $stats->available_car,
	totalCapacity: (int) $stats->total,
	totalAvailable: (int) $stats->total_available,
	availableVanSpaces: $availableVanSpaces,
);
```

---

## 6. API Design

| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| `POST` | `/api/park` | `{ license_plate, vehicle_type, parking_lot_id }` | `ParkingResource` (201) |
| `POST` | `/api/unpark` | `{ license_plate, parking_lot_id }` | `204 No Content` |
| `GET` | `/api/parking-lots/{id}/availability` | — | `AvailabilityResource` |

### Error Responses
- `400` — Validation errors (Form Request or `ValidationException` from action)
- `409` — No available spot or vehicle already parked (`NoAvailableSpotException`, `VehicleAlreadyParkedException`)
- `404` — Vehicle not found or not currently parked in this lot (`VehicleNotParkedException`)

### Routes (`routes/api.php`)

```php
Route::post("/park", [ParkingController::class, "park"]);
Route::post("/unpark", [ParkingController::class, "unpark"]);
Route::get("/parking-lots/{parkingLot}/availability", [ParkingController::class, "availability"]);
```

**Note:** The `{parkingLot}` parameter uses Laravel implicit route model binding. If the parking lot is not found, Laravel automatically returns a 404 JSON response for API routes.

### `ParkingController`

```php
class ParkingController extends Controller
{
	public function park(ParkVehicleRequest $request, ParkVehicle $action): ParkingResource;
	public function unpark(UnparkVehicleRequest $request, UnparkVehicle $action): JsonResponse;
	public function availability(ParkingLot $parkingLot, GetLotAvailability $action): AvailabilityResource;
}
```

### `ParkVehicleRequest` Validation Rules

```php
public function rules(): array
{
	return [
		"license_plate" => ["required", "string", "max:255"],
		"vehicle_type" => ["required", "string", Rule::enum(VehicleType::class)],
		"parking_lot_id" => ["required", "integer", "exists:parking_lots,id"],
	];
}
```

### `UnparkVehicleRequest` Validation Rules

```php
public function rules(): array
{
	return [
		"license_plate" => ["required", "string", "max:255"],
		"parking_lot_id" => ["required", "integer", "exists:parking_lots,id"],
	];
}
```

### `ParkingResource`

```php
class ParkingResource extends JsonResource
{
	public function toArray(Request $request): array
	{
		return [
			"id" => $this->id,
			"license_plate" => $this->vehicle->license_plate,
			"vehicle_type" => $this->vehicle->type,
			"parking_lot_id" => $this->parking_lot_id,
			"started_at" => $this->started_at,
			"spots" => $this->spots->map(fn ($spot) => [
				"id" => $spot->id,
				"type" => $spot->type,
				"section_id" => $spot->section_id,
				"position" => $spot->position,
			]),
		];
	}
}
```

**Note:** `ParkVehicle` action eager-loads `vehicle` and `spots` before returning the model, so the resource has no N+1 queries. `section_id` is included in spot data so clients can identify which section each spot belongs to.

### `AvailabilityResource`

```php
class AvailabilityResource extends JsonResource
{
	public function toArray(Request $request): array
	{
		return [
			"total_motorcycle_spots" => $this->totalMotorcycleSpots,
			"available_motorcycle_spots" => $this->availableMotorcycleSpots,
			"total_car_spots" => $this->totalCarSpots,
			"available_car_spots" => $this->availableCarSpots,
			"total_capacity" => $this->totalCapacity,
			"total_available" => $this->totalAvailable,
			"available_van_spaces" => $this->availableVanSpaces,
		];
	}
}
```

### Domain Exceptions (Self-Rendering)

Each exception defines its own `render()` method — no need to modify `bootstrap/app.php`. This is the Laravel 13 best practice for self-contained exceptions.

```php
class NoAvailableSpotException extends Exception
{
	public function __construct(string $message = "No available spot for this vehicle type.")
	{
		parent::__construct($message);
	}

	public function render(Request $request): JsonResponse
	{
		return response()->json(["message" => $this->getMessage()], Response::HTTP_CONFLICT);
	}
}
```

```php
class VehicleAlreadyParkedException extends Exception
{
	public function __construct(string $message = "Vehicle is already parked.")
	{
		parent::__construct($message);
	}

	public function render(Request $request): JsonResponse
	{
		return response()->json(["message" => $this->getMessage()], Response::HTTP_CONFLICT);
	}
}
```

```php
class VehicleNotParkedException extends Exception
{
	public function __construct(string $message = "Vehicle is not currently parked in this lot.")
	{
		parent::__construct($message);
	}

	public function render(Request $request): JsonResponse
	{
		return response()->json(["message" => $this->getMessage()], Response::HTTP_NOT_FOUND);
	}
}
```

---

## 7. DTOs, Enums & Requests

### `SpotType` (Enum)
```php
enum SpotType: string
{
	case Motorcycle = "motorcycle";
	case Car = "car";
}
```

### `VehicleType` (Enum)
```php
enum VehicleType: string
{
	case Motorcycle = "motorcycle";
	case Car = "car";
	case Van = "van";
}
```

### `ParkVehicleData`
```php
readonly class ParkVehicleData
{
	public function __construct(
		public string $licensePlate,
		public VehicleType $vehicleType,
		public int $parkingLotId,
	) {}
}
```

### `LotAvailabilityData`
```php
readonly class LotAvailabilityData
{
	public function __construct(
		public int $totalMotorcycleSpots,
		public int $availableMotorcycleSpots,
		public int $totalCarSpots,
		public int $availableCarSpots,
		public int $totalCapacity,
		public int $totalAvailable,
		public int $availableVanSpaces,
	) {}
}
```

### `UnparkVehicleData`
```php
readonly class UnparkVehicleData
{
	public function __construct(
		public string $licensePlate,
		public int $parkingLotId,
	) {}
}
```

---

## 8. SpotAllocator Service

The `SpotAllocator` is a pure domain service invoked by `ParkVehicle`. It holds no state and exposes two methods:

```php
class SpotAllocator
{
	/**
	 * @return array<int> Spot IDs to allocate.
	 * @throws NoAvailableSpotException
	 */
	public function allocate(int $parkingLotId, VehicleType $vehicleType): array;

	/**
	 * Count available van spaces (used by GetLotAvailability).
	 * Reuses the same gaps-and-islands algorithm as allocation,
	 * but counts all non-overlapping van spaces per run.
	 */
	public function countAvailableVanSpaces(int $parkingLotId): int;
}
```

- Motorcycle: tries a motorcycle spot first (`whereNull("parking_id")`), then falls back to a car spot.
- Car: allocates exactly one car spot (`whereNull("parking_id")`).
- Van: allocates three consecutive car spots within the same section.
- `countAvailableVanSpaces`: fetches available car spots via Eloquent, runs PHP gaps-and-islands to count non-overlapping van spaces.
- All allocation paths use `lockForUpdate()`; the calling `ParkVehicle` action wraps the full flow in `DB::transaction()` for atomicity.
- All queries use Eloquent ORM — **no raw SQL**.

---

## 9. Seeding

`ParkingLotSeeder` creates a fixed lot:
- 1 `ParkingLot` named "Main Lot"
- 2 `Section`s ("A", "B")
- Per section: 5 motorcycle spots (positions 1-5) + 10 car spots (positions 6-15)

Total: 10 motorcycle spots, 20 car spots, 30 total.

---

## 10. Testing Strategy

### Testing Conventions (Laravel 13 + PHP Best Practices)

| Practice | How We Apply It |
|----------|-----------------|
| **`RefreshDatabase` trait** | Every feature test class uses `use RefreshDatabase;` to ensure clean state per test. |
| **`$this->seed()` for fixtures** | The `ParkingLotSeeder` is run in `setUp()` to create the fixed lot structure before each test. |
| **AAA pattern** | Every test follows **Arrange** (seed + pre-conditions) -> **Act** (HTTP request / method call) -> **Assert** (response + DB state). |
| **Database assertions** | Use `assertDatabaseHas`, `assertDatabaseMissing`, `assertDatabaseCount` to verify side effects. |
| **One concept per test** | Each test validates exactly one scenario. Multiple assertions OK if they verify the same concept (e.g., "parking was created and spots are occupied"). |
| **Avoid redundant tests** | `SpotAllocator` is tested **indirectly** through feature tests for the full request cycle. No separate unit test file since the allocation logic is thin and the requirements ask for API endpoint tests. |
| **Descriptive test names** | Method names read as full sentences: `test_car_is_rejected_when_only_motorcycle_spots_remain()`. |

---

### Feature Tests (`tests/Feature/ParkingApiTest.php`)

> **Note:** The `ParkingLotSeeder` must support creating multiple lots for the multi-tenancy test. Extend it to accept an optional count or manually create a second lot in `test_parking_lot_is_multi_tenant`.

#### Group 1: Golden Path (Happy Path)

| Test | Scenario | Key Assertions |
|------|----------|----------------|
| `test_motorcycle_parks_in_a_motorcycle_spot` | Park a motorcycle; lot has motorcycle spots free. | `assertStatus(201)`, `assertDatabaseHas("parkings", ...)`, `assertDatabaseHas("spots", ["parking_id" => $parkingId])` |
| `test_motorcycle_parks_in_a_car_spot_when_motorcycle_spots_are_full` | Fill all motorcycle spots first, then park a motorcycle. | `assertStatus(201)`, spot type in response is `car`. |
| `test_car_parks_in_a_car_spot` | Park a car; car spots are available. | `assertStatus(201)`, spot has `parking_id` set |
| `test_van_parks_in_three_consecutive_car_spots` | Park a van; enough consecutive car spots exist. | `assertStatus(201)`, 3 spots have same `parking_id` |
| `test_unpark_frees_associated_spots` | Park then unpark a car. | After unpark: `assertDatabaseHas("parkings", ["ended_at" => ...])`, `assertDatabaseHas("spots", ["parking_id" => null])` |

#### Group 2: Rejection / Error Cases

| Test | Scenario | Key Assertions |
|------|----------|----------------|
| `test_car_is_rejected_when_only_motorcycle_spots_remain` | Occupy all car spots, then try to park a car. | `assertStatus(409)`, `assertDatabaseCount("parkings", 0)` |
| `test_van_is_rejected_when_car_spots_are_fragmented` | Create a fragmented pattern: car-MOTOR-car-car-MOTOR-car; van cannot fit. | `assertStatus(409)`, `assertDatabaseCount("parkings", 0)` |
| `test_van_is_rejected_when_fewer_than_three_car_spots_exist` | Occupy most car spots, leaving only 2 free. | `assertStatus(409)` |
| `test_park_fails_when_vehicle_is_already_parked` | Park a car, then try to park the same car again without unparking. | `assertStatus(409)` |
| `test_unpark_fails_when_vehicle_is_not_currently_parked` | Try to unpark a vehicle that was never parked. | `assertStatus(404)` |
| `test_unpark_fails_when_parking_lot_does_not_exist` | Unpark with invalid `parking_lot_id`. | `assertStatus(400)` (fails `exists:parking_lots,id` validation) |

#### Group 3: Validation Errors

| Test | Scenario | Key Assertions |
|------|----------|----------------|
| `test_park_fails_without_license_plate` | Missing `license_plate` field. | `assertStatus(400)`, `assertJsonValidationErrors("license_plate")` |
| `test_park_fails_with_invalid_vehicle_type` | `vehicle_type` not in `["motorcycle","car","van"]`. | `assertStatus(400)`, `assertJsonValidationErrors("vehicle_type")` |
| `test_park_fails_without_parking_lot_id` | Missing `parking_lot_id`. | `assertStatus(400)`, `assertJsonValidationErrors("parking_lot_id")` |
| `test_unpark_fails_without_license_plate` | Missing `license_plate` in unpark request. | `assertStatus(400)`, `assertJsonValidationErrors("license_plate")` |
| `test_park_fails_with_vehicle_type_mismatch` | Park a car, then try to park same license plate as a van. | `assertStatus(400)`, `assertJsonValidationErrors("vehicle_type")` |

#### Group 4: State & Availability

| Test | Scenario | Key Assertions |
|------|----------|----------------|
| `test_availability_reflects_total_capacity` | Fresh lot availability. | `assertJsonPath("total_capacity", 30)` |
| `test_availability_reflects_occupied_spots` | Park 1 car, check availability. | `available_car_spots` decremented by 1. |
| `test_van_occupancy_reduces_available_van_spaces` | After parking a van (3 spots), available van spaces decrease. | `availableVanSpaces` updated correctly. |
| `test_parking_lot_is_multi_tenant` | Park in Lot 1, check Lot 2 availability is unaffected. | Lot 2 shows full capacity. |

---

## 11. Key Decisions & Tradeoffs

| Decision | Rationale |
|----------|-----------|
| **Eloquent as domain model** | Single bounded context — no entity mapping overhead. |
| **No repository interfaces** | No persistence-swapping requirement. |
| **Actions (AaaS pattern)** | Single `handle()` method, injectable, testable. |
| **PHP scan for van allocation** | `lockForUpdate()` + `DB::transaction()` ensures atomicity. O(n) per section is efficient for bounded lot sizes. Single query (no Section model loading needed). |
| **Soft unpark (`ended_at`)** | Historical data preserved. Spots are freed by setting `parking_id = null`. |
| **`parking_id` FK on spots (no pivot table)** | Replaces both the `parking_spot` pivot table and the `is_occupied` denormalized column. One column serves two purposes: occupancy flag (`IS NULL` = available) and relationship FK. Eliminates a table, a column, and sync overhead. |
| **Denormalized `parking_lot_id` on spots** | Avoids JOIN through `sections` on every allocation/availability query. Sections are static/seeded so consistency risk is negligible. |
| **`string` columns + PHP Enum casting** | PostgreSQL enum types require raw SQL to alter. `string` columns with Eloquent `$casts` are more maintainable. |
| **Self-rendering exceptions** | `render()` on exception class is the Laravel 13 best practice — no `bootstrap/app.php` modification needed. |
| **PostgreSQL partial indexes** | Smaller indexes that only cover rows matching the WHERE predicate. Auto-maintained when `parking_id` changes. More efficient than full composite indexes for hot-path allocation queries. |
| **PostgreSQL `FILTER` clause** | Computes multiple conditional aggregates in a single table scan. Faster than `CASE WHEN` and eliminates 4+ separate `count()` queries in `GetLotAvailability`. |
| **PHP gaps-and-islands for van availability** | Same algorithm as allocation, uses Eloquent query builder — **no raw SQL**. The CTE approach would require `DB::select()` which bypasses the query builder. |
| **Bulk `Spot::whereIn()->update()`** | Single UPDATE query instead of N individual `$spot->save()` calls. Prevents N+1 writes in park. |
| **`Spot::where("parking_id", $id)->update()`** | Single UPDATE to free all spots on unpark — no eager-load + pluck needed. Simpler than the pivot approach. |
| **No `timestamps()` on `parkings`** | `started_at`/`ended_at` are the business timestamps. `created_at` is redundant with `started_at`, `updated_at` is redundant with `ended_at`. |
| **`Model::shouldBeStrict()`** | Laravel 13 best practice — catches N+1 queries (lazy loading), silently discarded attributes, and missing attribute access during development. More comprehensive than `preventLazyLoading()` alone. |
| **Partial index on active parkings** | `parkings_active_idx` only indexes rows where `ended_at IS NULL` — a tiny fraction of all parkings. Makes "is vehicle already parked?" check O(1). |
| **`createOrFirst()` for vehicles** | Race-condition safe: INSERT-first, SELECT-fallback on unique constraint violation. Better than `firstOrCreate()` (SELECT-first) for concurrent environments. Requires unique constraint on `license_plate` (which we have). |
| **Explicit `parking_lot_id` index on spots** | PostgreSQL does NOT auto-create FK indexes (unlike MySQL). This index is required for the availability FILTER query and allocation queries. |
| **No standalone `section_id` index** | The unique composite `(section_id, position)` covers `section_id` queries as the leading column. PostgreSQL 18 skip scan makes this even more efficient. |

---

## 12. AppServiceProvider Configuration

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

public function boot(): void
{
    // Prevent N+1 queries, silently discarded attributes, and missing
    // attribute access in non-production environments.
    Model::shouldBeStrict(!$this->app->isProduction());

    // Remove "data" wrapper from API resource responses.
    JsonResource::withoutWrapping();
}
```

This is the Laravel 13 best practice for catching bugs during development. `shouldBeStrict()` is more comprehensive than `preventLazyLoading()` alone. In production, strict mode is disabled to avoid breaking the app if an accidental violation slips through code review.

---

## 13. Query Efficiency Analysis

Every endpoint's query count is documented below. **No N+1 queries exist.**

### `POST /api/park` — ParkVehicle

| Step | Query | Index Used |
|------|-------|------------|
| Find/create vehicle | `INSERT INTO vehicles ...` (+ possible `SELECT` on unique violation) | `vehicles_license_plate_unique` |
| Check already parked | `SELECT ... FROM parkings WHERE vehicle_id = ? AND ended_at IS NULL` | `parkings_active_idx` |
| **Motorcycle** allocation | `SELECT ... FROM spots WHERE parking_lot_id = ? AND type = 'motorcycle' AND parking_id IS NULL LIMIT 1 FOR UPDATE` | `spots_available_motorcycle_lot_idx` |
| **Motorcycle fallback** | `SELECT ... FROM spots WHERE parking_lot_id = ? AND type = 'car' AND parking_id IS NULL LIMIT 1 FOR UPDATE` | `spots_available_car_lot_idx` |
| **Car** allocation | Same as motorcycle fallback | `spots_available_car_lot_idx` |
| **Van** allocation | `SELECT ... FROM spots WHERE parking_lot_id = ? AND type = 'car' AND parking_id IS NULL ORDER BY section_id, position FOR UPDATE` | `spots_available_car_section_idx` |
| Create parking | `INSERT INTO parkings ...` | — |
| Bulk update spots | `UPDATE spots SET parking_id = ? WHERE id IN (...)` | PK |
| Eager load relations | `SELECT ... FROM vehicles WHERE id = ?` + `SELECT ... FROM spots WHERE parking_id IN (...)` | PK |

**Total: 5–7 queries** (depending on vehicle type and fallback path). All are single-statement, index-covered queries. No N+1.

### `POST /api/unpark` — UnparkVehicle

| Step | Query | Index Used |
|------|-------|------------|
| Find vehicle | `SELECT ... FROM vehicles WHERE license_plate = ?` | `vehicles_license_plate_unique` |
| Find active parking | `SELECT ... FROM parkings WHERE vehicle_id = ? AND parking_lot_id = ? AND ended_at IS NULL` | `parkings_active_idx` |
| Update parking | `UPDATE parkings SET ended_at = ? WHERE id = ?` | PK |
| Bulk free spots | `UPDATE spots SET parking_id = NULL WHERE parking_id = ?` | — (1–3 rows, sequential scan fine) |

**Total: 4 queries.** No N+1. No eager-load needed — spots freed directly by `parking_id`.

### `GET /api/parking-lots/{id}/availability` — GetLotAvailability

| Step | Query | Index Used |
|------|-------|------------|
| Spot counts (FILTER) | Single `SELECT count(*) filter (...) FROM spots WHERE parking_lot_id = ?` | `spots_parking_lot_id_index` |
| Van availability (Eloquent + PHP) | `SELECT section_id, position FROM spots WHERE parking_lot_id = ? AND type = 'car' AND parking_id IS NULL ORDER BY ...` | `spots_available_car_section_idx` |

**Total: 2 queries** (down from N+1 with per-section loading + 4+ count queries). **No raw SQL** — both use Laravel's query builder.

### Index Summary

| Table | Index | Type | Purpose |
|-------|-------|------|---------|
| `vehicles` | `license_plate` | UNIQUE | Vehicle lookup by plate + `createOrFirst()` unique constraint |
| `sections` | `parking_lot_id` | BTREE (explicit) | Sections by lot — PostgreSQL does NOT auto-create FK indexes |
| `spots` | `section_id, position` | UNIQUE | Spot identity within section + covers `section_id` queries (leading column) |
| `spots` | `parking_lot_id` | BTREE (explicit) | Availability FILTER query + allocation queries — **not** auto-created by PostgreSQL |
| `spots` | `section_id, position WHERE type='car' AND parking_id IS NULL` | **PARTIAL** | Van allocation |
| `spots` | `parking_lot_id WHERE type='motorcycle' AND parking_id IS NULL` | **PARTIAL** | Motorcycle allocation |
| `spots` | `parking_lot_id WHERE type='car' AND parking_id IS NULL` | **PARTIAL** | Car allocation + motorcycle fallback |
| `parkings` | `vehicle_id, parking_lot_id WHERE ended_at IS NULL` | **PARTIAL** | Active parking lookup |

**No redundant indexes.** No standalone `section_id` index — covered by unique composite `(section_id, position)` as leading column (PG 18 skip scan helps). No `parking_id` index on spots — unpark returns 1–3 rows, sequential scan is fine. No `parking_spot` pivot — eliminated entirely.

---

## 14. Execution Order

1. **Migrations** — Create a single migration (`2026_05_13_000001_create_parking_schema.php`) that creates all 5 tables in dependency order, adds the deferred `spots.parking_id` foreign key after `parkings` exists, and creates PostgreSQL partial indexes via `DB::statement()`.
2. **AppServiceProvider** — Add `Model::shouldBeStrict(!$this->app->isProduction())` and `JsonResource::withoutWrapping()` in `boot()`.
3. **Seeder** — `ParkingLotSeeder` with fixed lot structure.
4. **Enums & DTOs** — `SpotType`, `VehicleType`, `ParkVehicleData`, `UnparkVehicleData`, `LotAvailabilityData`.
5. **Models** — Eloquent models with `$fillable`, `$casts`, relationships (Parking→spots is `HasMany` via `parking_id`), and `$timestamps = false` on `Parking`.
6. **Exceptions** — `NoAvailableSpotException`, `VehicleAlreadyParkedException`, `VehicleNotParkedException`.
7. **`SpotAllocator` Service** — Pure allocation logic using Eloquent ORM + `lockForUpdate()` + `whereNull("parking_id")`.
8. **Actions** — `ParkVehicle` (with `createOrFirst()`), `UnparkVehicle` (with direct `parking_id` free), `GetLotAvailability` (with PostgreSQL `FILTER` + PHP gaps-and-islands — **no raw SQL**).
9. **Controllers, Requests, Resources** — Thin HTTP layer.
10. **Routes** — `routes/api.php`.
11. **Tests** — Feature tests in `ParkingApiTest.php`.

---

