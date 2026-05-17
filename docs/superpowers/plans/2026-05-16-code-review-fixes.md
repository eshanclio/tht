# Code Review Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Constraint:** No git commands. No `git commit`, no `git worktree`. Each task ends with a `make test` checkpoint instead.

**Goal:** Resolve every finding in [CODE-REVIEW.md](../../../CODE-REVIEW.md) so the parking-lot API is a clean reference for Laravel 13.8 + PHP 8.3 + light-DDD on the [requirements.md](../../../requirements.md) problem.

**Architecture:** Edit-in-place on a single init commit. Migration is rewritten (no production data exists); models rename `Parking` → `ParkingSession` with table name preserved via `protected $table`. Vehicles become per-lot. Advisory-lock scope narrowed to van path. Van availability uses a query-builder `selectRaw` gaps-and-islands query. Domain `VehicleTypeMismatchException` returns 409.

**Tech Stack:** Laravel 13.8, PHP 8.3, PostgreSQL 16, Pest/PHPUnit (Laravel default), `laravel/pint`. All commands run through [Makefile](../../../Makefile) targets: `make test`, `make fresh`, `make migrate`, `make artisan CMD=…`, `make composer CMD=…`, `make shell`.

**Spec:** [docs/superpowers/specs/2026-05-16-code-review-fixes-design.md](../specs/2026-05-16-code-review-fixes-design.md).

---

## Conventions used in this plan

- File paths are relative to repo root (`/Users/shahnoor.eshan/work/exp-projects/tht/`).
- "Checkpoint: `make test`" means run the full suite and verify it stays green. Do not advance to the next task on a red suite.
- "Checkpoint: `make fresh`" means rebuild the DB schema (drops + re-runs migrations + seed). Use only after migration edits.
- Code blocks are complete — paste them in as shown unless an explicit "merge into existing" note says otherwise.
- Indentation in PHP files: the repo currently uses **tabs**. Preserve tabs in edits — Pint will reconcile in the final step.
- Never delete a test without replacing its coverage.

---

## Task 1 — Migration: vehicles multi-tenant uniqueness + parkings `created_at` + comment audit

**Issue refs:** CODE-REVIEW.md §2.1, §3.8, §3.9, §3.10

**Files:**
- Modify: `src/database/migrations/2026_05_13_000001_create_parking_schema.php`

- [ ] **Step 1.1: Replace the migration file contents**

  Open `src/database/migrations/2026_05_13_000001_create_parking_schema.php` and replace the whole file with:

  ```php
  <?php

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Database\Schema\Blueprint;
  use Illuminate\Support\Facades\DB;
  use Illuminate\Support\Facades\Schema;

  return new class extends Migration
  {
  	public function up(): void
  	{
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

  		// `spots.parking_lot_id` is denormalized from `sections.parking_lot_id`
  		// for partial-index selectivity. The API does not move sections between
  		// lots, so the two columns stay in sync. If section relocation is ever
  		// added, this invariant must be enforced (trigger or app-level check).
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
  			$table->foreignId("parking_id")
  				->nullable();
  			$table->timestamps();

  			$table->unique(["section_id", "position"]);
  			$table->index("parking_lot_id");
  			$table->index("parking_id");
  		});

  		// Vehicles are scoped per lot so the same plate can independently exist
  		// at two lots (real multi-tenancy). The composite unique closes the
  		// "same plate twice in the same lot" hole.
  		Schema::create("vehicles", function (Blueprint $table) {
  			$table->id();
  			$table->foreignId("parking_lot_id")
  				->constrained()
  				->cascadeOnDelete();
  			$table->string("license_plate");
  			$table->string("type");
  			$table->timestamps();

  			$table->unique(["parking_lot_id", "license_plate"]);
  		});

  		// `parkings` carries `started_at`/`ended_at` for business semantics;
  		// `created_at` is kept as a cheap insertion audit trail. No `updated_at`:
  		// rows are only mutated to set `ended_at` once, and the model overrides
  		// UPDATED_AT to null.
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
  			$table->timestamp("created_at")->nullable();
  		});

  		// Circular reference: spots.parking_id -> parkings.id, but spots is
  		// created before parkings (parkings carries the parking_lot_id FK).
  		// Note: spots cascade-delete from parking_lots already, so the
  		// nullOnDelete here only matters for the unpark flow.
  		Schema::table("spots", function (Blueprint $table) {
  			$table->foreign("parking_id")
  				->references("id")
  				->on("parkings")
  				->nullOnDelete();
  		});

  		// Partial indexes: each index is scoped to the rows the allocator
  		// queries against, keeping them small and selective.
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

  		// Closes the duplicate-active-parking race at the DB layer. The
  		// ParkVehicle action catches UniqueConstraintViolationException as
  		// a fallback for the gap between read and write.
  		DB::statement(
  			"CREATE UNIQUE INDEX parkings_active_vehicle_unique ON parkings (vehicle_id)"
  			." WHERE ended_at IS NULL"
  		);
  	}

  	public function down(): void
  	{
  		Schema::table("spots", function (Blueprint $table) {
  			$table->dropForeign(["parking_id"]);
  		});

  		Schema::dropIfExists("parkings");
  		Schema::dropIfExists("vehicles");
  		Schema::dropIfExists("spots");
  		Schema::dropIfExists("sections");
  		Schema::dropIfExists("parking_lots");
  	}
  };
  ```

- [ ] **Step 1.2: Rebuild the DB schema**

  Run: `make fresh`
  Expected: migrations run cleanly, seeder populates one lot. Last line shows "Database seeding completed successfully."

- [ ] **Step 1.3: Verify the suite is currently red where expected, green elsewhere**

  Run: `make test`
  Expected: feature tests that construct `Vehicle` rows without `parking_lot_id` will fail. Record which tests fail — they will be fixed in Task 6. All other tests should still pass. Note the failing count.

---

## Task 2 — Rename `Parking` model → `ParkingSession` (table stays `parkings`)

**Issue refs:** CODE-REVIEW.md §4 first bullet (naming), §6.7

**Files:**
- Delete: `src/app/Domains/Parking/Models/Parking.php`
- Create: `src/app/Domains/Parking/Models/ParkingSession.php`
- Modify: `src/app/Domains/Parking/Models/Spot.php`
- Modify: `src/app/Domains/Parking/Actions/ParkVehicle.php` (imports + types only — full rewrite in Task 5)
- Modify: `src/app/Domains/Parking/Actions/UnparkVehicle.php` (imports + types only — full rewrite in Task 7)
- Modify: `src/app/Domains/Parking/Actions/GetLotAvailability.php` (if it imports `Parking`)
- Modify: `src/tests/Feature/ParkingApiTest.php` (imports + class references only)

- [ ] **Step 2.1: Create the new `ParkingSession` model**

  Write `src/app/Domains/Parking/Models/ParkingSession.php`:

  ```php
  <?php

  namespace App\Domains\Parking\Models;

  use Illuminate\Database\Eloquent\Attributes\Scope;
  use Illuminate\Database\Eloquent\Builder;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;
  use Illuminate\Database\Eloquent\Relations\HasMany;

  class ParkingSession extends Model
  {
  	protected $table = "parkings";

  	public const UPDATED_AT = null;

  	protected $fillable = ["parking_lot_id", "vehicle_id", "started_at", "ended_at"];

  	protected $with = ["vehicle", "spots"];

  	protected function casts(): array
  	{
  		return [
  			"started_at" => "datetime",
  			"ended_at" => "datetime",
  		];
  	}

  	public function parkingLot(): BelongsTo
  	{
  		return $this->belongsTo(ParkingLot::class);
  	}

  	public function vehicle(): BelongsTo
  	{
  		return $this->belongsTo(Vehicle::class);
  	}

  	public function spots(): HasMany
  	{
  		return $this->hasMany(Spot::class, "parking_id");
  	}

  	#[Scope]
  	protected function active(Builder $query): void
  	{
  		$query->whereNull("ended_at");
  	}
  }
  ```

- [ ] **Step 2.2: Delete the old `Parking` model**

  Delete `src/app/Domains/Parking/Models/Parking.php` (filesystem rm via your editor — no `git rm`).

- [ ] **Step 2.3: Update `Spot` to reference the new model**

  In `src/app/Domains/Parking/Models/Spot.php`, change the import and the relation name. Replace the file with:

  ```php
  <?php

  namespace App\Domains\Parking\Models;

  use App\Domains\Parking\Data\SpotType;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  class Spot extends Model
  {
  	protected $fillable = ["parking_lot_id", "section_id", "type", "position", "parking_id"];

  	protected function casts(): array
  	{
  		return [
  			"type" => SpotType::class,
  		];
  	}

  	public function parkingLot(): BelongsTo
  	{
  		return $this->belongsTo(ParkingLot::class);
  	}

  	public function section(): BelongsTo
  	{
  		return $this->belongsTo(Section::class);
  	}

  	public function parkingSession(): BelongsTo
  	{
  		return $this->belongsTo(ParkingSession::class, "parking_id");
  	}
  }
  ```

- [ ] **Step 2.4: Replace `use App\Domains\Parking\Models\Parking;` with `use App\Domains\Parking\Models\ParkingSession;` in every consumer**

  Search-and-replace in these files only (do **not** mass-replace across all files — be deliberate):
  - `src/app/Domains/Parking/Actions/ParkVehicle.php` — replace import, change every `Parking::` → `ParkingSession::`, change return type `: Parking` → `: ParkingSession`.
  - `src/app/Domains/Parking/Actions/UnparkVehicle.php` — replace import, change every `Parking::` → `ParkingSession::`.
  - `src/app/Domains/Parking/Actions/GetLotAvailability.php` — only if it imports the class. Check with `grep -n "Parking" src/app/Domains/Parking/Actions/GetLotAvailability.php` first.
  - `src/tests/Feature/ParkingApiTest.php` — replace import and every `Parking::` → `ParkingSession::`. Leave the **test class name itself** (`ParkingApiTest`) and route paths (`/parkings`?) alone — those are not the model.

  Confirm no stragglers: run `make shell` and inside the container `grep -rn "Models\\\\Parking" /var/www/html/app /var/www/html/tests`. Expected: zero matches (other than `ParkingSession` and `ParkingLot`).

- [ ] **Step 2.5: Refresh the Composer autoloader**

  Run: `make composer CMD=dump-autoload`
  Expected: "Generated optimized autoload files."

- [ ] **Step 2.6: Checkpoint — run the suite**

  Run: `make test`
  Expected: same failure set as Task 1.3 (vehicles still need `parking_lot_id`); no new failures introduced by the rename. If new failures appear, they are missed `Parking::` references — fix and re-run.

---

## Task 3 — `Vehicle` model: multi-tenant fillable + relation

**Issue refs:** CODE-REVIEW.md §2.1, §6.1

**Files:**
- Modify: `src/app/Domains/Parking/Models/Vehicle.php`

- [ ] **Step 3.1: Add `parking_lot_id` to fillable and add the relation**

  Replace `src/app/Domains/Parking/Models/Vehicle.php` with:

  ```php
  <?php

  namespace App\Domains\Parking\Models;

  use App\Domains\Parking\Data\VehicleType;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  class Vehicle extends Model
  {
  	protected $fillable = ["parking_lot_id", "license_plate", "type"];

  	protected function casts(): array
  	{
  		return [
  			"type" => VehicleType::class,
  		];
  	}

  	public function parkingLot(): BelongsTo
  	{
  		return $this->belongsTo(ParkingLot::class);
  	}
  }
  ```

- [ ] **Step 3.2: Checkpoint — `make test`**

  Same failure set as before. No new errors expected from the model change in isolation.

---

## Task 4 — `Section` model: add relations (no longer anemic)

**Issue refs:** CODE-REVIEW.md §3.7

**Files:**
- Modify: `src/app/Domains/Parking/Models/Section.php`

- [ ] **Step 4.1: Add relations**

  Replace `src/app/Domains/Parking/Models/Section.php` with:

  ```php
  <?php

  namespace App\Domains\Parking\Models;

  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;
  use Illuminate\Database\Eloquent\Relations\HasMany;

  class Section extends Model
  {
  	protected $fillable = ["parking_lot_id", "name"];

  	public function parkingLot(): BelongsTo
  	{
  		return $this->belongsTo(ParkingLot::class);
  	}

  	public function spots(): HasMany
  	{
  		return $this->hasMany(Spot::class);
  	}
  }
  ```

- [ ] **Step 4.2: Checkpoint — `make test`**

  No behavior change expected.

---

## Task 5 — Domain exception: `VehicleTypeMismatchException` (409)

**Issue refs:** CODE-REVIEW.md §2.4, §6.2

**Files:**
- Create: `src/app/Domains/Parking/Exceptions/VehicleTypeMismatchException.php`

- [ ] **Step 5.1: Create the exception**

  Write `src/app/Domains/Parking/Exceptions/VehicleTypeMismatchException.php`:

  ```php
  <?php

  namespace App\Domains\Parking\Exceptions;

  use App\Domains\Parking\Data\VehicleType;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;
  use RuntimeException;

  class VehicleTypeMismatchException extends RuntimeException
  {
  	public function __construct(
  		public readonly VehicleType $recordedType,
  		public readonly VehicleType $requestedType,
  	) {
  		parent::__construct(
  			"Vehicle type does not match the existing record."
  		);
  	}

  	public function render(Request $request): JsonResponse
  	{
  		return response()->json([
  			"message" => $this->getMessage(),
  			"recorded_type" => $this->recordedType->value,
  			"requested_type" => $this->requestedType->value,
  		], 409);
  	}
  }
  ```

- [ ] **Step 5.2: Checkpoint — `make test`**

  No behavior change yet (exception not thrown anywhere). Suite state unchanged.

---

## Task 6 — Rewrite `ParkVehicle`: per-lot vehicles, transaction-scoped, enum identity, conditional advisory lock, drop redundant load

**Issue refs:** CODE-REVIEW.md §2.1, §2.2, §2.3, §2.4, §3.1, §3.4, §3.5, §3.6

**Files:**
- Modify: `src/app/Domains/Parking/Actions/ParkVehicle.php`

- [ ] **Step 6.1: Replace the action**

  Replace `src/app/Domains/Parking/Actions/ParkVehicle.php` with:

  ```php
  <?php

  namespace App\Domains\Parking\Actions;

  use App\Domains\Parking\Data\ParkVehicleData;
  use App\Domains\Parking\Data\VehicleType;
  use App\Domains\Parking\Exceptions\VehicleAlreadyParkedException;
  use App\Domains\Parking\Exceptions\VehicleTypeMismatchException;
  use App\Domains\Parking\Models\ParkingSession;
  use App\Domains\Parking\Models\Spot;
  use App\Domains\Parking\Models\Vehicle;
  use App\Domains\Parking\Services\SpotAllocator;
  use Illuminate\Database\UniqueConstraintViolationException;
  use Illuminate\Support\Facades\DB;

  class ParkVehicle
  {
  	// Manual namespace for pg_advisory_xact_lock. Bump if other features
  	// start using advisory locks and risk colliding on the same (ns, id) key.
  	private const VAN_LOCK_NAMESPACE = 1;

  	public function __construct(
  		private SpotAllocator $spotAllocator,
  	) {}

  	public function handle(ParkVehicleData $data): ParkingSession
  	{
  		try {
  			return DB::transaction(function () use ($data) {
  				$vehicle = Vehicle::firstOrCreate(
  					[
  						"parking_lot_id" => $data->parkingLotId,
  						"license_plate" => $data->licensePlate,
  					],
  					["type" => $data->vehicleType],
  				);

  				if ($vehicle->type !== $data->vehicleType) {
  					throw new VehicleTypeMismatchException(
  						$vehicle->type,
  						$data->vehicleType,
  					);
  				}

  				// Vans need the per-lot advisory lock to make consecutive-spot
  				// allocation atomic across concurrent requests. Cars and
  				// motorcycles rely on SELECT ... FOR UPDATE against the partial
  				// indexes plus the parkings_active_vehicle_unique constraint.
  				if ($data->vehicleType === VehicleType::Van) {
  					DB::statement(
  						"SELECT pg_advisory_xact_lock(?, ?)",
  						[self::VAN_LOCK_NAMESPACE, $data->parkingLotId],
  					);
  				}

  				$existingParking = ParkingSession::query()
  					->where("vehicle_id", $vehicle->id)
  					->active()
  					->lockForUpdate()
  					->first();

  				if ($existingParking) {
  					throw new VehicleAlreadyParkedException();
  				}

  				$spotIds = $this->spotAllocator->allocate(
  					$data->parkingLotId,
  					$data->vehicleType,
  				);

  				$parking = ParkingSession::create([
  					"parking_lot_id" => $data->parkingLotId,
  					"vehicle_id" => $vehicle->id,
  					"started_at" => now(),
  				]);

  				Spot::whereIn("id", $spotIds)
  					->update(["parking_id" => $parking->id]);

  				return $parking;
  			});
  		} catch (UniqueConstraintViolationException) {
  			throw new VehicleAlreadyParkedException();
  		}
  	}
  }
  ```

  Notes on what changed (do not paste these into the file):
  - `Vehicle::createOrFirst` → `Vehicle::firstOrCreate` inside the transaction, scoped by `parking_lot_id` + `license_plate`.
  - `$vehicle->type->value !== $data->vehicleType->value` → enum identity comparison.
  - `ValidationException` → domain `VehicleTypeMismatchException` (rendered 409).
  - Advisory lock only acquired for the Van path.
  - `whereNull("ended_at")` → `->active()` scope.
  - `$parking->load(["vehicle", "spots"])` removed — model's `$with` covers it.

- [ ] **Step 6.2: Checkpoint — `make test`**

  Expected: the previously-failing vehicle-creation tests now pass (Vehicle now has the FK and `firstOrCreate` supplies it). The "same plate, different vehicle type" test fails because it still asserts the old 422 response shape — this gets fixed in Task 9.

---

## Task 7 — `UnparkVehicle`: scope to active + per-lot vehicle lookup

**Issue refs:** CODE-REVIEW.md §2.1 (multi-tenant lookup), §3.6 (active scope)

**Files:**
- Modify: `src/app/Domains/Parking/Actions/UnparkVehicle.php`

- [ ] **Step 7.1: Replace the action**

  Replace `src/app/Domains/Parking/Actions/UnparkVehicle.php` with:

  ```php
  <?php

  namespace App\Domains\Parking\Actions;

  use App\Domains\Parking\Data\UnparkVehicleData;
  use App\Domains\Parking\Exceptions\VehicleNotParkedException;
  use App\Domains\Parking\Models\ParkingSession;
  use App\Domains\Parking\Models\Spot;
  use App\Domains\Parking\Models\Vehicle;
  use Illuminate\Support\Facades\DB;

  class UnparkVehicle
  {
  	public function handle(UnparkVehicleData $data): void
  	{
  		$vehicle = Vehicle::query()
  			->where("parking_lot_id", $data->parkingLotId)
  			->where("license_plate", $data->licensePlate)
  			->first();

  		if (!$vehicle) {
  			throw new VehicleNotParkedException();
  		}

  		DB::transaction(function () use ($data, $vehicle) {
  			$parking = ParkingSession::query()
  				->where("vehicle_id", $vehicle->id)
  				->where("parking_lot_id", $data->parkingLotId)
  				->active()
  				->lockForUpdate()
  				->first();

  			if (!$parking) {
  				throw new VehicleNotParkedException();
  			}

  			$parking->update(["ended_at" => now()]);

  			Spot::where("parking_id", $parking->id)
  				->update(["parking_id" => null]);
  		});
  	}
  }
  ```

- [ ] **Step 7.2: Checkpoint — `make test`**

  Expected: same failure set as Task 6 (only the 422→409 test).

---

## Task 8 — `SpotAllocator`: single-query motorcycle, scoped van locking, gaps-and-islands `selectRaw`

**Issue refs:** CODE-REVIEW.md §3.2, §3.3, §6.5, §6.6

**Files:**
- Modify: `src/app/Domains/Parking/Services/SpotAllocator.php`

- [ ] **Step 8.1: Replace the allocator**

  Replace `src/app/Domains/Parking/Services/SpotAllocator.php` with:

  ```php
  <?php

  namespace App\Domains\Parking\Services;

  use App\Domains\Parking\Data\SpotType;
  use App\Domains\Parking\Data\VehicleType;
  use App\Domains\Parking\Exceptions\NoAvailableSpotException;
  use App\Domains\Parking\Models\Spot;
  use Illuminate\Support\Facades\DB;

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
  	 * Motorcycles prefer a motorcycle spot but fall back to a car spot.
  	 * One ordered query covers both: motorcycle rows sort first via CASE.
  	 *
  	 * @return array<int>
  	 * @throws NoAvailableSpotException
  	 */
  	private function allocateMotorcycle(int $parkingLotId): array
  	{
  		$spot = Spot::query()
  			->where("parking_lot_id", $parkingLotId)
  			->whereNull("parking_id")
  			->orderByRaw("CASE WHEN type = 'motorcycle' THEN 0 ELSE 1 END")
  			->orderBy("id")
  			->lockForUpdate()
  			->first();

  		if (!$spot) {
  			throw new NoAvailableSpotException();
  		}

  		return [(int) $spot->id];
  	}

  	/**
  	 * @return array<int>
  	 * @throws NoAvailableSpotException
  	 */
  	private function allocateCar(int $parkingLotId): array
  	{
  		$spot = Spot::query()
  			->where("parking_lot_id", $parkingLotId)
  			->where("type", SpotType::Car->value)
  			->whereNull("parking_id")
  			->lockForUpdate()
  			->first();

  		if (!$spot) {
  			throw new NoAvailableSpotException();
  		}

  		return [(int) $spot->id];
  	}

  	/**
  	 * Vans claim three consecutive car spots within the same section.
  	 * The per-lot advisory lock (acquired by ParkVehicle) serializes the
  	 * read-then-write; the row-level lockForUpdate on the three winners is
  	 * a belt-and-braces guard before the UPDATE.
  	 *
  	 * @return array<int>
  	 * @throws NoAvailableSpotException
  	 */
  	private function allocateVan(int $parkingLotId): array
  	{
  		$spots = Spot::query()
  			->where("parking_lot_id", $parkingLotId)
  			->where("type", SpotType::Car->value)
  			->whereNull("parking_id")
  			->orderBy("section_id")
  			->orderBy("position")
  			->get(["id", "section_id", "position"]);

  		$winningIds = null;

  		foreach ($spots->groupBy("section_id") as $sectionSpots) {
  			if ($sectionSpots->count() < 3) {
  				continue;
  			}

  			$values = $sectionSpots->values();
  			$positions = $values->pluck("position");

  			for ($i = 0; $i <= $positions->count() - 3; $i++) {
  				if ($positions[$i + 2] - $positions[$i] === 2) {
  					$winningIds = [
  						(int) $values[$i]->id,
  						(int) $values[$i + 1]->id,
  						(int) $values[$i + 2]->id,
  					];
  					break 2;
  				}
  			}
  		}

  		if ($winningIds === null) {
  			throw new NoAvailableSpotException();
  		}

  		$locked = Spot::query()
  			->whereIn("id", $winningIds)
  			->whereNull("parking_id")
  			->lockForUpdate()
  			->get();

  		if ($locked->count() !== 3) {
  			throw new NoAvailableSpotException();
  		}

  		return $winningIds;
  	}

  	/**
  	 * Count van placements via gaps-and-islands. Free car spots within a
  	 * section form consecutive runs; subtracting ROW_NUMBER() from position
  	 * yields a constant value (`grp`) for each run. Each run of length L
  	 * contributes floor(L / 3) non-overlapping van placements.
  	 */
  	public function countAvailableVanSpaces(int $parkingLotId): int
  	{
  		$grouped = DB::table("spots")
  			->where("parking_lot_id", $parkingLotId)
  			->where("type", SpotType::Car->value)
  			->whereNull("parking_id")
  			->selectRaw(
  				"section_id, position - ROW_NUMBER() OVER ("
  				."PARTITION BY section_id ORDER BY position) AS grp"
  			);

  		$runs = DB::query()
  			->fromSub($grouped, "g")
  			->selectRaw("COUNT(*) AS run_length")
  			->groupBy("section_id", "grp");

  		return (int) DB::query()
  			->fromSub($runs, "r")
  			->selectRaw("COALESCE(SUM(FLOOR(run_length / 3)), 0) AS total")
  			->value("total");
  	}
  }
  ```

- [ ] **Step 8.2: Checkpoint — `make test`**

  Expected: motorcycle / car / van allocation tests pass. Availability tests pass. Only the 422→409 mismatch test still fails.

---

## Task 9 — Update feature tests: 409 mismatch, multi-tenant uniqueness, fragmentation helper

**Issue refs:** CODE-REVIEW.md §2.4, §2.1 (test coverage), §4 (fragmentation helper)

**Files:**
- Modify: `src/tests/Feature/ParkingApiTest.php`

- [ ] **Step 9.1: Update the type-mismatch test to expect 409**

  In `src/tests/Feature/ParkingApiTest.php`, find the test at/around line 332 (the one currently asserting 422 with a `vehicle_type` validation error for "same plate, different vehicle type"). Replace its assertions block so the response is checked as:

  ```php
  $response->assertStatus(409)
  	->assertJson([
  		"message" => "Vehicle type does not match the existing record.",
  		"recorded_type" => "car",     // whichever type was recorded first
  		"requested_type" => "motorcycle", // whichever type was requested
  	]);
  ```

  Adjust the `recorded_type` / `requested_type` literals to match the test's setup. The point: replace `assertStatus(422)` and the `errors.vehicle_type` assertion with the structured 409 body assertion.

- [ ] **Step 9.2: Add a "same plate at two different lots" test**

  Append to the same test class:

  ```php
  public function test_same_plate_can_park_at_two_different_lots(): void
  {
  	$lotA = ParkingLot::factory()->create();
  	$lotB = ParkingLot::factory()->create();
  	$this->seedMixedLot($lotA);
  	$this->seedMixedLot($lotB);

  	$plate = "DUPLICATE-PLATE-1";

  	$responseA = $this->postJson("/api/park", [
  		"license_plate" => $plate,
  		"vehicle_type" => "car",
  		"parking_lot_id" => $lotA->id,
  	]);
  	$responseA->assertStatus(201);

  	$responseB = $this->postJson("/api/park", [
  		"license_plate" => $plate,
  		"vehicle_type" => "van",
  		"parking_lot_id" => $lotB->id,
  	]);
  	$responseB->assertStatus(201);

  	$this->assertDatabaseCount("vehicles", 2);
  }
  ```

  If `seedMixedLot` does not exist, use whichever existing seeder helper the test class already uses (e.g. `$this->seedLot($lot, motorcycleSpots: 2, carSpots: 10)` — check the existing tests in the same file for the actual helper name and signature).

- [ ] **Step 9.3: Extract a fragmentation helper**

  Find the test that frees spots via `whereIn("position", [7, 8, 10, 11, 13, 14, 15])` (around line 188). Replace the inline call with a helper. Add this private method to the test class (above the test methods, after any existing private helpers):

  ```php
  private function freeSpotsAtPositions(Section $section, array $positions): void
  {
  	Spot::query()
  		->where("section_id", $section->id)
  		->whereIn("position", $positions)
  		->update(["parking_id" => null]);
  }
  ```

  Update the offending test body to call `$this->freeSpotsAtPositions($section, [7, 8, 10, 11, 13, 14, 15]);` instead of the inline `Spot::query()->...->update(...)`. Add `use App\Domains\Parking\Models\Section;` and `use App\Domains\Parking\Models\Spot;` imports at the top of the test file if not already present.

- [ ] **Step 9.4: Checkpoint — `make test`**

  Expected: full suite green.

---

## Task 10 — `ParkVehicleRequest`: use `enum()` helper

**Issue refs:** CODE-REVIEW.md §4 (`ParkVehicleRequest`)

**Files:**
- Modify: `src/app/Http/Requests/ParkVehicleRequest.php`

- [ ] **Step 10.1: Replace the request**

  Replace `src/app/Http/Requests/ParkVehicleRequest.php` with:

  ```php
  <?php

  namespace App\Http\Requests;

  use App\Domains\Parking\Data\VehicleType;
  use Illuminate\Foundation\Http\FormRequest;
  use Illuminate\Validation\Rule;

  class ParkVehicleRequest extends FormRequest
  {
  	public function authorize(): bool
  	{
  		return true;
  	}

  	public function rules(): array
  	{
  		return [
  			"license_plate" => ["required", "string", "max:255"],
  			"vehicle_type" => ["required", "string", Rule::enum(VehicleType::class)],
  			"parking_lot_id" => ["required", "integer", "exists:parking_lots,id"],
  		];
  	}

  	public function vehicleType(): VehicleType
  	{
  		return $this->enum("vehicle_type", VehicleType::class);
  	}
  }
  ```

- [ ] **Step 10.2: Checkpoint — `make test`**

  Expected: green.

---

## Task 11 — `ParkingResource`: explicit enum serialization

**Issue refs:** CODE-REVIEW.md §4 (`ParkingResource`)

**Files:**
- Modify: `src/app/Http/Resources/ParkingResource.php`

- [ ] **Step 11.1: Replace the resource**

  Replace `src/app/Http/Resources/ParkingResource.php` with:

  ```php
  <?php

  namespace App\Http\Resources;

  use Illuminate\Http\Request;
  use Illuminate\Http\Resources\Json\JsonResource;

  class ParkingResource extends JsonResource
  {
  	public function toArray(Request $request): array
  	{
  		return [
  			"id" => $this->id,
  			"license_plate" => $this->vehicle->license_plate,
  			"vehicle_type" => $this->vehicle->type->value,
  			"parking_lot_id" => $this->parking_lot_id,
  			"started_at" => $this->started_at,
  			"spots" => $this->spots->map(fn ($spot) => [
  				"id" => $spot->id,
  				"type" => $spot->type->value,
  				"section_id" => $spot->section_id,
  				"position" => $spot->position,
  			]),
  		];
  	}
  }
  ```

- [ ] **Step 11.2: Checkpoint — `make test`**

  Expected: green. (Enum serialization output is identical — both forms emit the backing string — but the source now states intent.)

---

## Task 12 — Delete empty base `Controller`, trim empty bootstrap closures

**Issue refs:** CODE-REVIEW.md §6.8

**Files:**
- Delete: `src/app/Http/Controllers/Controller.php`
- Modify: `src/app/Http/Controllers/ParkingController.php`
- Modify: `src/bootstrap/app.php`

- [ ] **Step 12.1: Drop `extends Controller` from `ParkingController`**

  Open `src/app/Http/Controllers/ParkingController.php` and:
  - Remove the line `class ParkingController extends Controller` → `class ParkingController`.
  - There is no `use App\Http\Controllers\Controller` line to remove (same namespace).

  No other change.

- [ ] **Step 12.2: Delete the base controller file**

  Delete `src/app/Http/Controllers/Controller.php`.

- [ ] **Step 12.3: Trim empty bootstrap closures**

  Replace `src/bootstrap/app.php` with:

  ```php
  <?php

  use Illuminate\Foundation\Application;

  return Application::configure(basePath: dirname(__DIR__))
      ->withRouting(
          api: __DIR__."/../routes/api.php",
          commands: __DIR__."/../routes/console.php",
          health: "/up",
      )
      ->create();
  ```

- [ ] **Step 12.4: Refresh autoload + checkpoint**

  Run: `make composer CMD=dump-autoload`
  Then run: `make test`
  Expected: green. If the autoloader still references `App\Http\Controllers\Controller`, double-check Step 12.1.

---

## Task 13 — Comments sweep

**Issue refs:** user instruction ("keep comments correct and consistent")

**Files (read-only scan + targeted edits):**
- All files touched in Tasks 1–12.

- [ ] **Step 13.1: Walk every touched file and remove stale comments**

  For each file modified in Tasks 1–12, open it and:
  - Remove any comment that references the old name `Parking` (the model) or the old behavior (always-on advisory lock, in-PHP gap counter, ValidationException for type mismatch). Examples of stale comments to delete: doc-comments on private methods that now have self-evident names; `// note: …` lines that describe behavior the code no longer has.
  - Keep the two targeted explainer comments added in this plan:
    - `ParkVehicle::VAN_LOCK_NAMESPACE` rationale (added in Task 6).
    - `SpotAllocator::countAvailableVanSpaces` gaps-and-islands explainer (added in Task 8).
    - The four migration comments added in Task 1 (spots denormalization, vehicles tenancy, parkings timestamps, circular FK, partial indexes purpose).
  - Do **not** add new narrative comments to code that's now self-explanatory (e.g., DTOs, scope methods, factory boilerplate).
  - Do **not** remove `@throws` / `@return` PHPDoc on `SpotAllocator` — those are signals, not noise.

- [ ] **Step 13.2: Checkpoint — `make test`**

  Expected: green (comments only — no behavior change).

---

## Task 14 — Run Pint and re-verify

**Issue refs:** CODE-REVIEW.md §4 (tabs/spaces)

**Files:** every PHP file in `src/`.

- [ ] **Step 14.1: Run Pint**

  Run: `make composer CMD="exec pint"`
  Expected: Pint reformats inconsistent files. Output lists the number of files changed.

  If `pint` is not exposed as a composer script, run instead inside the container:

  ```bash
  make shell
  # inside container:
  ./vendor/bin/pint
  exit
  ```

- [ ] **Step 14.2: Final checkpoint — full suite**

  Run: `make test`
  Expected: green.

- [ ] **Step 14.3: Static grep for stragglers**

  Run inside the container (`make shell`, then):

  ```bash
  grep -rn "Models\\\\Parking[^L|S]" app tests database || echo "no stragglers"
  grep -rn "createOrFirst" app || echo "no createOrFirst left"
  grep -rn "ValidationException" app/Domains/Parking || echo "no ValidationException in domain"
  grep -rn "whereNull(\"ended_at\")" app || echo "no raw whereNull(ended_at) left"
  ```

  Expected: every grep prints "no … left". If any grep returns matches, fix them and re-run `make test`.

---

## Acceptance criteria checklist

- [ ] Every issue in [CODE-REVIEW.md](../../../CODE-REVIEW.md) is either resolved by a task above, or explicitly noted as out-of-scope in the spec §5.
- [ ] `make test` passes.
- [ ] New tests cover (a) same plate at two lots and (b) the 409 mismatch body shape.
- [ ] No `DB::statement` / `DB::select` outside the advisory-lock call in `ParkVehicle`.
- [ ] No reference to the old `Parking` model name anywhere in `app/`, `tests/`, `database/`.
- [ ] Pint is clean.

---

## Self-review notes (run mentally before handoff)

**Spec coverage:** every section of the spec maps to a task above (Task 1 = §3.1 migration; Task 2 = §3.2 ParkingSession rename; Task 3 = §3.2 Vehicle; Task 4 = §3.2 Section; Task 5 = §3.3 exception; Task 6 = §3.4 ParkVehicle; Task 7 = §3.4 UnparkVehicle; Task 8 = §3.5 allocator; Tasks 10–12 = §3.6 HTTP; Task 9 = §3.8 tests; Task 14 = §3.9 tooling; Task 13 = §2 comments).

**Type consistency:** the new model class is `ParkingSession` throughout (Tasks 2, 6, 7, 9). The new exception is `VehicleTypeMismatchException` throughout (Tasks 5, 6, 9). The constant is `ParkVehicle::VAN_LOCK_NAMESPACE` (declared Task 6, referenced only there). The allocator method names — `allocate`, `countAvailableVanSpaces` — are unchanged.

**Placeholder scan:** none.

**Risks:** migration drop-and-rebuild requires `make fresh`, which is non-destructive only because there's no production data. Confirmed acceptable in the spec.
