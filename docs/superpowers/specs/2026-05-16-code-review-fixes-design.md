# Design — Code Review Remediation (Roofr Parking Lot)

**Date:** 2026-05-16
**Stack:** Laravel 13.8, PHP 8.3, PostgreSQL
**Source of findings:** [CODE-REVIEW.md](../../../CODE-REVIEW.md)
**Constraints (from user):**

- No git commands (no commit, no worktree).
- Queries must use Eloquent / query builder. `selectRaw` / `orderByRaw` are acceptable escape hatches; `DB::statement` / `DB::select` with hand-written SQL is not (except where Postgres advisory locks make it the only option, which is already justified in the existing code).
- All commands run through the project [Makefile](../../../Makefile) (`make test`, `make migrate`, `make artisan CMD=...`, etc.).
- Keep code comments correct: remove now-stale ones and add only the targeted explainers called out below. Default to no comments where names are self-explanatory.

## 1. Goal

Bring the codebase to a state where every finding in `CODE-REVIEW.md` is resolved or explicitly accepted, while preserving the existing test surface and adding coverage for the new behaviors. After this change, the project should be a clean reference for Laravel 13 + PHP 8.3 + light-DDD patterns on a real domain problem.

## 2. Locked decisions (from brainstorming)

| # | Question | Choice |
|---|----------|--------|
| D1 | Van availability counting | Window-function gaps-and-islands via `selectRaw` on the query builder. No `DB::statement`. |
| D2 | Multi-tenant vehicle uniqueness | Composite unique `(parking_lot_id, license_plate)`. Vehicles become per-lot. |
| D3 | Type-mismatch HTTP status | New `VehicleTypeMismatchException`, rendered as `409 Conflict`. |
| D4 | Rename `Parking` model | `ParkingSession`, table stays `parkings` via `protected $table`. |

## 3. Changes by area

### 3.1 Migration ([src/database/migrations/2026_05_13_000001_create_parking_schema.php](../../../src/database/migrations/2026_05_13_000001_create_parking_schema.php))

The project has a single init commit and no production data, so the existing migration is edited in place rather than adding a new migration.

- **vehicles**: add `foreignId('parking_lot_id')->constrained()->cascadeOnDelete()`. Replace `$table->unique('license_plate')` with `$table->unique(['parking_lot_id', 'license_plate'])`.
- **spots**: leave `parking_lot_id` denormalized from `sections.parking_lot_id`. Add a one-line comment in the migration explaining the rationale (partial-index selectivity) and the invariant (must stay in sync; the API does not move sections between lots).
- **parkings**: add `$table->timestamp('created_at')->nullable()` and let Eloquent manage it via `const UPDATED_AT = null` on the model. (`started_at` is set by the action; `created_at` is a cheap audit trail.)
- **timestamps audit comment**: remove if it goes stale; no new comments needed elsewhere.

### 3.2 Models

#### `Vehicle` ([src/app/Domains/Parking/Models/Vehicle.php](../../../src/app/Domains/Parking/Models/Vehicle.php))

- Add `parking_lot_id` to `$fillable`.
- Add `parkingLot(): BelongsTo` relation.

#### `Section` ([src/app/Domains/Parking/Models/Section.php](../../../src/app/Domains/Parking/Models/Section.php))

- Add `$fillable = ['parking_lot_id', 'name']`.
- Add `parkingLot(): BelongsTo` and `spots(): HasMany` relations.

#### `Parking` → `ParkingSession` ([src/app/Domains/Parking/Models/Parking.php](../../../src/app/Domains/Parking/Models/Parking.php))

- Rename file and class.
- `protected $table = 'parkings'` (no schema rename needed).
- `public const UPDATED_AT = null` (created_at managed natively).
- Default eager-load: `protected $with = ['vehicle', 'spots']`.
- Add `active()` query scope using Laravel 12+ `#[Scope]` attribute:

  ```php
  #[\Illuminate\Database\Eloquent\Attributes\Scope]
  protected function active(\Illuminate\Database\Eloquent\Builder $query): void
  {
      $query->whereNull('ended_at');
  }
  ```

#### `Spot` ([src/app/Domains/Parking/Models/Spot.php](../../../src/app/Domains/Parking/Models/Spot.php))

- Rename relation `parking()` → `parkingSession()`, pass `parking_id` foreign key explicitly to `belongsTo`.
- Update any caller (resource, allocator) that traverses `->parking`.

### 3.3 Domain — Exceptions

New file [src/app/Domains/Parking/Exceptions/VehicleTypeMismatchException.php](../../../src/app/Domains/Parking/Exceptions/VehicleTypeMismatchException.php):

- Extends `\RuntimeException`.
- Constructor accepts the recorded `VehicleType` and the requested `VehicleType`.
- `render(Request $request): JsonResponse` returns 409 with body `{ message, recorded_type, requested_type }`.

### 3.4 Domain — Actions

#### `ParkVehicle` ([src/app/Domains/Parking/Actions/ParkVehicle.php](../../../src/app/Domains/Parking/Actions/ParkVehicle.php))

- Introduce `private const VAN_LOCK_NAMESPACE = 1;` — comment: "manual advisory-lock namespace; bump if other features start using `pg_advisory_xact_lock`."
- Move `Vehicle::firstOrCreate(...)` inside `DB::transaction()` so a failed allocation rolls back the vehicle row.
- Scope the lookup: `firstOrCreate(['parking_lot_id' => $data->parkingLotId, 'license_plate' => $data->licensePlate], ['type' => $data->vehicleType])`.
- Compare enums by identity: `if ($vehicle->type !== $data->vehicleType) { throw new VehicleTypeMismatchException($vehicle->type, $data->vehicleType); }`.
- Acquire the advisory lock only when `$data->vehicleType === VehicleType::Van`. Continue to use `DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [self::VAN_LOCK_NAMESPACE, $data->parkingLotId])` — this is the one place raw SQL is unavoidable.
- Drop the now-redundant `$parking->load('vehicle', 'spots')` call after the default `$with` ships.
- Use `->active()` on the duplicate-active check.

#### `UnparkVehicle` ([src/app/Domains/Parking/Actions/UnparkVehicle.php](../../../src/app/Domains/Parking/Actions/UnparkVehicle.php))

- Replace `whereNull('ended_at')` with `->active()`.

### 3.5 Domain — Allocator

`SpotAllocator` ([src/app/Domains/Parking/Services/SpotAllocator.php](../../../src/app/Domains/Parking/Services/SpotAllocator.php)) stays where it is (the `Services/` folder name is acceptable; the alternative — folding into the action — hurts testability). Internal rewrites:

#### `allocateMotorcycle`

Single ordered query:

```php
$spot = Spot::query()
    ->where('parking_lot_id', $parkingLotId)
    ->whereNull('parking_id')
    ->orderByRaw("CASE WHEN type = 'motorcycle' THEN 0 ELSE 1 END")
    ->orderBy('id')
    ->lockForUpdate()
    ->first();
```

#### `allocateVan`

- Inside the action's transaction the advisory lock already serializes per-lot, so the wide `lockForUpdate()` over every free car spot is dropped.
- After the gap-detection algorithm picks the three winning IDs, re-fetch them under `lockForUpdate()` before the UPDATE as a belt-and-braces guard:

  ```php
  $winners = Spot::whereIn('id', $winningIds)
      ->whereNull('parking_id')
      ->lockForUpdate()
      ->get();

  if ($winners->count() !== 3) {
      throw new NoAvailableSpotException();
  }
  ```

#### `countAvailableVanSpaces` — gaps-and-islands via `selectRaw`

Replace the in-PHP loop with a query-builder chain (still goes through Eloquent's connection, no `DB::statement`):

```php
// Gaps-and-islands: spots whose (position - row_number_within_section) match
// belong to the same consecutive run. Each run of length L contributes
// floor(L / 3) van-eligible placements.
$grouped = DB::table('spots')
    ->where('parking_lot_id', $parkingLotId)
    ->where('type', SpotType::Car->value)
    ->whereNull('parking_id')
    ->selectRaw('section_id, position - ROW_NUMBER() OVER (PARTITION BY section_id ORDER BY position) AS grp');

$runs = DB::query()
    ->fromSub($grouped, 'g')
    ->selectRaw('COUNT(*) AS run_length')
    ->groupBy('section_id', 'grp');

return (int) DB::query()
    ->fromSub($runs, 'r')
    ->selectRaw('COALESCE(SUM(FLOOR(run_length / 3)), 0) AS total')
    ->value('total');
```

Comment above the block: "Gaps-and-islands: free car spots within a section form consecutive runs; each run of length L yields ⌊L/3⌋ van placements."

### 3.6 HTTP layer

#### `ParkingController` ([src/app/Http/Controllers/ParkingController.php](../../../src/app/Http/Controllers/ParkingController.php))

- Remove `extends Controller`.

#### Delete base controller

- Delete [src/app/Http/Controllers/Controller.php](../../../src/app/Http/Controllers/Controller.php).

#### `ParkVehicleRequest` ([src/app/Http/Requests/ParkVehicleRequest.php](../../../src/app/Http/Requests/ParkVehicleRequest.php))

- Replace manual `VehicleType::from($this->validated('vehicle_type'))` (or equivalent) with `$this->enum('vehicle_type', VehicleType::class)`.

#### `ParkingResource` ([src/app/Http/Resources/ParkingResource.php](../../../src/app/Http/Resources/ParkingResource.php))

- Change `$this->vehicle->type` → `$this->vehicle->type->value` for explicit intent.

#### `bootstrap/app.php` ([src/bootstrap/app.php](../../../src/bootstrap/app.php))

- Remove the empty `->withMiddleware(...)` and `->withExceptions(...)` calls if their closures are empty.

### 3.7 Seeder ([src/database/seeders/ParkingLotSeeder.php](../../../src/database/seeders/ParkingLotSeeder.php))

- No structural change. Verify it still works after the rename and Section model changes.

### 3.8 Tests ([src/tests/Feature/ParkingApiTest.php](../../../src/tests/Feature/ParkingApiTest.php))

- Update all `Parking::` references to `ParkingSession::` (and any factory paths).
- Update the type-mismatch test (line 332) to assert `assertStatus(409)` and the new body shape (`recorded_type`, `requested_type`).
- Add a new test: same plate, different lots, parked simultaneously with the same OR different vehicle types — both succeed.
- Add a new test: same plate, same lot, mismatched type — 409.
- Refactor the fragmentation test (line 188-191) to use a `freeSpotsAtPositions(Section $section, array $positions)` private helper.
- All other tests should pass unchanged; run `make test` after each major step.

### 3.9 Tooling

- Run `./vendor/bin/pint` (via `make composer CMD="run pint"` if a script is defined, otherwise add a one-liner script in `composer.json` or call directly inside the container). Single pass after all edits land.

## 4. Execution order

Each step ends with `make test` (and the test suite must stay green at each checkpoint).

1. **Migration + model rename** — single migration edit; rename `Parking` → `ParkingSession`; update `Spot::parkingSession()`; `composer dump-autoload` via `make composer CMD=dump-autoload`; `make fresh` to rebuild DB.
2. **Vehicle multi-tenancy** — model FK, fillable, relation; update `ParkVehicle::firstOrCreate`; add tests for same-plate-different-lots.
3. **Transaction + enum-identity + 409 exception** — move `firstOrCreate` inside transaction; introduce `VehicleTypeMismatchException`; update existing test to 409.
4. **Advisory-lock scoping** — `VAN_LOCK_NAMESPACE` constant; conditional on `Van`; document.
5. **Allocator rewrites** — `allocateMotorcycle` single query; `allocateVan` lock tightening; `countAvailableVanSpaces` gaps-and-islands `selectRaw`.
6. **Active scope + eager-load defaults** — `#[Scope]` on `ParkingSession`; `$with`; remove redundant `->load(...)`.
7. **HTTP & bootstrap cleanup** — delete base `Controller`, remove empty bootstrap closures, `$this->enum()`, explicit `->type->value` in resource.
8. **Section model fleshing** — fillable + relations.
9. **Comments sweep** — remove stale comments, ensure the two new explainer comments (advisory-lock namespace, gaps-and-islands) are in place. Default to no comments where names suffice.
10. **Pint pass** — final style normalization.
11. **Full suite** — `make test` once more, plus a manual smoke via `make artisan CMD="route:list"` to confirm nothing wired up changed.

## 5. Out of scope (deferred / not addressed)

- `parkings` schema redesign for sharded lots — not needed at this scale.
- Distributed-lock strategy beyond Postgres advisory locks — single-DB assumption holds.
- Moving allocator to a dedicated package / interface — `SpotAllocator` stays a concrete class.
- Replacing the controller-route-action pattern with single-action invokable controllers — defensible but cosmetic; not addressed.

## 6. Risks

- The window-function `selectRaw` is Postgres-only (`ROW_NUMBER() OVER (PARTITION BY ...)`). MySQL 8+ supports it too, but the project pins Postgres in `compose.yml` so this is fine. Documented in the spec; one comment in the allocator.
- Renaming `Parking` → `ParkingSession` touches ~10 files. Mechanical, but easy to miss a string reference in a test fixture. Mitigated by running `make test` after step 1 before continuing.
- Adding `parking_lot_id` to `vehicles` retroactively requires `make fresh` (DB drop). The init commit has no production data; safe.

## 7. Acceptance criteria

- All findings in `CODE-REVIEW.md` either resolved or explicitly noted in §5 above.
- `make test` is green.
- `./vendor/bin/pint --test` passes.
- No `DB::statement` / `DB::select` outside the existing advisory-lock call.
- Code grep `Parking::` (model) returns zero matches outside the rename comment trail.
- New tests cover the per-lot vehicle uniqueness and the 409 mismatch response.
