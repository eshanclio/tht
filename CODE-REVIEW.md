# Code Review — Parking Lot Management

## Summary

Strong, ship-ready submission for the stated scope (Roofr coding-challenge sized work). All 26 feature tests pass (`src/storage/test-output.txt:6`), every requirement in `requirements.md` is satisfied, the architecture is intentional (Actions + DTOs + a thin allocator service), concurrency is handled defensibly (per-lot advisory lock for vans + partial unique index for duplicate-active-parking), and Postgres partial indexes are well-matched to the access patterns. The remaining issues are polish, modest cleanup, and a handful of minor structural choices the author could defend either way. **Verdict: ship.**

## Critical Issues

None. The previous reviewer's "vehicles.license_plate global unique breaks multi-tenancy" criticism has been fully addressed — `vehicles` now has `UNIQUE(parking_lot_id, license_plate)` (`src/database/migrations/2026_05_13_000001_create_parking_schema.php:64`) and `test_same_plate_can_park_at_two_different_lots` (`src/tests/Feature/ParkingApiTest.php:315`) proves it works end-to-end. The vehicle-create-outside-transaction race the prior review flagged is also resolved: `Vehicle::firstOrCreate` now sits *inside* `DB::transaction` (`src/app/Domains/Parking/Actions/ParkVehicle.php:29-30`).

## Important Issues

### I-1. `UnparkVehicleRequest` is dead code
`src/app/Http/Requests/UnparkVehicleRequest.php` is never referenced by any controller — `ParkingController::unpark` takes `ParkingLot $parkingLot, string $licensePlate, UnparkVehicle $action` directly from route segments (`src/app/Http/Controllers/ParkingController.php:33`). Grep confirms zero callers outside the composer autoload map. The class also still declares an `exists:parking_lots,id` rule on `parking_lot_id`, which is stale since the lot moved to the URL. **Delete the file.** It is misleading documentation about request validation that never runs.

### I-2. `firstOrCreate` is SELECT-first, race-prone
`src/app/Domains/Parking/Actions/ParkVehicle.php:30-36` uses `firstOrCreate`. Under concurrent inserts of the same `(parking_lot_id, license_plate)` pair, two parallel transactions can both miss the SELECT and try to INSERT — one wins, the other gets a `UniqueConstraintViolationException` that the surrounding catch (`:85`) misreads as `VehicleAlreadyParkedException` (it is actually a *vehicle-row* conflict, not a session conflict). The plan specified `createOrFirst()` (INSERT-first, SELECT-fallback). That is the correct primitive here and matches the documented intent on `implementation-plan.md:337`. Swap `firstOrCreate` → `createOrFirst` so the catch block only ever fires on the *session* unique constraint.

### I-3. `existingParking` check is redundant given the partial unique index
`src/app/Domains/Parking/Actions/ParkVehicle.php:59-67` does a `SELECT … FOR UPDATE` for an active parking, then *also* relies on `parkings_active_vehicle_unique` (`migration:111`) + `UniqueConstraintViolationException` catch (`ParkVehicle.php:85-87`). The application-level check is one extra round-trip and the lock it acquires is unhelpful: there is no row to lock if the vehicle is freshly inserted. The DB constraint alone is sufficient and race-free. Either remove the pre-check and rely on the exception, or remove the exception catch and trust the lock. Right now both layers exist for a check that is already enforced at the schema layer.

### I-4. `ParkingSession::$with = ['vehicle', 'spots']` is global eager loading
`src/app/Domains/Parking/Models/ParkingSession.php:19` always eager-loads `vehicle` and `spots` for every `ParkingSession::find/get/...`. Inside `ParkVehicle::handle` the `existingParking` lookup (`ParkVehicle.php:59-63`) fetches a session purely for an existence check — and pays for two unnecessary joins on the *conflict* path even though no spots can yet be attached to a not-yet-created session. Prefer explicit `->load()` after the successful create, or replace the duplicate check with `->exists()` to skip hydration entirely.

### I-5. Re-lock on the three van winners is redundant with the advisory lock
`src/app/Domains/Parking/Services/SpotAllocator.php:120-128` — after the PHP scan picks three IDs, it re-`SELECT ... FOR UPDATE` on them with `whereNull('parking_id')`. Inside a transaction holding `pg_advisory_xact_lock(1, lot_id)`, no other van transaction can have grabbed those spots between the initial scan and the lock. Cars/motorcycles cannot take car spots in single-spot allocation if the van's `lockForUpdate` on those rows hasn't been issued yet — but actually the *initial* `get()` at `:86-92` did **not** issue `lockForUpdate`, so a concurrent *car* allocation against the same row remains possible. So the re-lock is not wholly redundant; it is the *only* protection against a concurrent car allocation grabbing one of the chosen rows. Keep it, but add a comment that clarifies "advisory lock serializes van-vs-van; row lock guards van-vs-car for these three rows." Defensible as-is, but the existing comment on `:74-78` is wrong — it claims "belt and braces," which understates the role.

### I-6. `Spot::parking_id` integer cast is missing
`src/app/Domains/Parking/Models/Spot.php:13-18` only casts `type`. The implementation plan (line 202) calls for `'parking_id' => 'integer'` to make the nullable-FK reads typed. Not load-bearing today since `ParkingResource` reads `$spot->id` and `$spot->section_id` directly (which Eloquent typecasts via the primary-key handling for `id` only). Add it for consistency with the plan and to lock the contract for any future consumer.

### I-7. `Vehicle` model has no `parkingSessions()` relation
`src/app/Domains/Parking/Models/Vehicle.php` declares only `parkingLot()`. The implementation plan (`implementation-plan.md:225`) specifies a `parkings(): HasMany` relation. None of the current code calls it, but it is a one-liner that makes the model usable for any future "history" query, and it is specified by the plan. Add it or note the removal.

## Minor Issues / Nits

- `src/app/Http/Resources/ParkingResource.php:17` — `started_at` is serialized via Carbon default formatting since there is no explicit format; the README example shows ISO 8601 with microseconds (`README.md:44`). Works but implicit. Consider `$this->started_at?->toIso8601String()` or set a default in the model.
- `src/app/Http/Controllers/ParkingController.php:18-26` — the controller hand-builds the DTO. A static `ParkVehicleData::fromRequest(ParkVehicleRequest $req, int $lotId)` would keep the controller a one-liner. Minor.
- `src/app/Domains/Parking/Models/Section.php:9-22` — `parkingLot()` and `spots()` are only used by the seeder/tests. Keep if you intend to expose section-level queries; otherwise drop.
- `src/app/Domains/Parking/Models/ParkingLot.php:7` — no relations. The plan had `sections()` and `parkings()`. Not consumed today, so the omission is defensible.
- `src/database/migrations/2026_05_13_000001_create_parking_schema.php:81` — `parkings.created_at` is `nullable()` and the model overrides `UPDATED_AT = null`. Defensible; the comment at `:67-70` explains it.
- `src/database/migrations/2026_05_13_000001_create_parking_schema.php:49` — `index('parking_id')` on spots is required for unpark's `where('parking_id', $id)` (Postgres does not auto-index FKs). Good catch.
- `src/storage/test-output.txt`, `src/storage/v.txt` — accidental commits; add to `.gitignore`.
- `docs/` directory exists but is untracked; commit or remove.
- `src/app/Domains/Parking/Actions/ParkVehicle.php:20` — `VAN_LOCK_NAMESPACE = 1` with a comment is documented enough; prior review's request satisfied.
- `src/app/Domains/Parking/Services/SpotAllocator.php:139-159` — gaps-and-islands rewritten from in-PHP loop to pure SQL. The prior reviewer's biggest perf concern, now resolved. Excellent.
- Tabs vs spaces — files are consistently 4-space PSR-12 now. Pint was clearly run.
- `bootstrap/app.php:19-31` — `ModelNotFoundException` → 404 JSON conversion is clean and the prior reviewer's "noise" comment is moot since the closure now does real work.
- `src/app/Http/Resources/ParkingResource.php:15` — `$this->vehicle->type->value` is the correct explicit form (the prior reviewer's nit). Good.

## Strengths

- Postgres partial indexes precisely sized to hot queries (`migration:97-113`).
- `parkings_active_vehicle_unique` partial unique index is the right DB-layer guarantee against the duplicate-active-parking race (`migration:110-113`).
- Per-lot `pg_advisory_xact_lock` is gated to van requests only (`ParkVehicle.php:49-54`) — cars and motorcycles are not serialized. Prior reviewer's split-the-lock request satisfied.
- Van algorithm correctness verified including the section-boundary exact-fit case (`ParkingApiTest.php:445`).
- `countAvailableVanSpaces` is now pure SQL using `ROW_NUMBER()` (`SpotAllocator.php:139-159`). Prior reviewer's #1 perf concern, resolved.
- `Vehicle` is per-lot via composite unique `(parking_lot_id, license_plate)` — multi-tenancy hole closed, and exercised by `test_same_plate_can_park_at_two_different_lots`.
- DTOs are `readonly` with promoted properties.
- Enum casts on every relevant model.
- `Model::shouldBeStrict(!$app->isProduction())` in `AppServiceProvider.php:24` — catches lazy loading in CI, disabled in prod.
- `#[Scope]` attribute on `ParkingSession::active` (`ParkingSession.php:44-48`) — Laravel 12+ idiom used correctly.
- `JsonResource::withoutWrapping()` matches the documented response shape.
- Test coverage genuinely thorough: 26 tests / 77 assertions, all passing. Motorcycle fallback, van fragmentation, van section-boundary exact-fit, full-lot rejection for all three vehicle types, multi-tenancy isolation, wrong-lot unpark, type-mismatch handling, validation errors, route-binding 404s.
- `bootstrap/app.php` cleanly converts framework `ModelNotFoundException` → 404 JSON with model name.
- Deleted `App\Http\Controllers\Controller` and stale `Parking` model with zero dangling references (`git status` confirms; grep confirms).
- README is accurate, well-organized, and explains tradeoffs explicitly.

## Per-Area Findings

### Requirements coverage
Every required capability and rule from `requirements.md` is implemented:
- Park / unpark / availability endpoints (`routes/api.php:6-8`).
- Vehicle-type ↔ spot-type rules match the table exactly (`SpotAllocator.php`).
- Sections constrain van placement (`SpotAllocator.php:96`).
- Van atomicity is guaranteed via advisory lock + row lock + transaction (`ParkVehicle.php:49-83`, `SpotAllocator.php:120-128`).
- "No availability" rejects with `NoAvailableSpotException` (`NoAvailableSpotException.php`).
- Multi-tenancy implemented and tested (`ParkingApiTest.php:315, 391, 418`).
- Layered architecture is genuine, not cosmetic.
- DTOs are readonly + typed.
- Named test scenarios from the requirements doc all present: motorcycle fallback, car blocked, van success, van rejected on fragmentation, van departure frees all 3 (`ParkingApiTest.php:48, 157, 77, 170, 122`).

### Laravel 13 / PHP 8.3 idioms
Modern syntax throughout: readonly DTOs with constructor promotion, backed enums, `match` (`SpotAllocator.php:20-24`), typed everything, `casts()` method (Laravel 11+), `#[Scope]` attribute (Laravel 12+). `bootstrap/app.php` uses the minimal Laravel 11+ structure. Controllers and actions use constructor DI rather than facades where reasonable (`DB::` is used inside actions, which is fine). The deprecated `App\Http\Controllers\Controller` base class is correctly removed. One miss: `firstOrCreate` should be `createOrFirst` for race-safety (I-2).

### Algorithm (SpotAllocator + park/unpark)
- Motorcycle: single ordered query with `CASE WHEN type = 'motorcycle' THEN 0 ELSE 1 END`. Cleaner than the two-query fallback in the plan; loses partial-index coverage but the README accurately reflects this and the seeded scale makes it irrelevant.
- Car: trivial and correct.
- Van: `get()` (no row lock) → PHP scan over groups by section → re-`lockForUpdate` on the three winners. The re-lock guards van-vs-car races on those three rows (since cars can grab a car spot without an advisory lock). See I-5.
- Park: vehicle upsert → optional advisory lock → active-session check → allocate → create session → bulk update spots. Order is correct. Both layers of duplicate-prevention is over-defensive (I-3) but not wrong.
- Unpark: vehicle lookup → transaction → active-session `lockForUpdate` → set `ended_at` → bulk free spots by `parking_id`. Correct and minimal.

### Database queries & N+1
- `GetLotAvailability`: 2 queries (FILTER count + gaps-and-islands subquery). Excellent.
- `ParkVehicle`: ~6 queries on the happy path. Global `$with` on `ParkingSession` causes the active-session lookup to eagerly load 2 unneeded relations (I-4).
- `UnparkVehicle`: 4 queries. No N+1.
- `ParkingResource` is safe from N+1 because the action returns a freshly-created session with `$with` auto-loading. Coupling (I-4) but no perf bug.

### Schema & indexes
- FKs and cascade-on-delete correct.
- Circular FK (`spots.parking_id → parkings.id`) handled via deferred `Schema::table` after `parkings` exists; down migration drops the FK first. Correct.
- Partial indexes match queries: `(section_id, position) WHERE car AND null` for van scan; `(parking_lot_id) WHERE car AND null` for car allocation; `(vehicle_id) WHERE ended_at IS NULL UNIQUE` for active-parking guard.
- Plain indexes: `spots(parking_lot_id)` for availability `FILTER`, `spots(parking_id)` for unpark.
- `vehicles(parking_lot_id, license_plate)` composite unique — correct for multi-tenancy.
- No `(vehicle_id, parking_lot_id)` index on `parkings`; the unique partial index on `vehicle_id` is sufficient (one row max, `parking_lot_id` re-checked from heap). Acceptable at this scale.
- No redundant indexes detected. No missing indexes for the documented hot paths.

### DDD structure
Actions / Data / Models / Services / Exceptions layout is clean. One service (`SpotAllocator`) is a fair price for keeping the van algorithm out of `ParkVehicle`. Domain exceptions self-render — no leaky boundary. Controllers are thin. The only mild leak is `GetLotAvailability` reaching into `DB::table('spots')` (`GetLotAvailability.php:17`); justified because the `FILTER` aggregation isn't Eloquent-shaped and adding a static helper for one call would be over-architecture. The prior reviewer's "Section is anemic" criticism is now moot — `Section` has both relations.

### Dead code
- `src/app/Http/Requests/UnparkVehicleRequest.php` — unused, delete (I-1).
- `src/storage/test-output.txt`, `src/storage/v.txt` — accidental commits, gitignore.
- Deleted files (`Parking.php`, `Controller.php`) — verified zero references remain via grep.

### API surface
- Routes are RESTful: `POST /parking-lots/{lot}/sessions`, `DELETE /parking-lots/{lot}/vehicles/{plate}`, `GET /parking-lots/{lot}/availability`. Cleaner than the plan's flat `/park`, `/unpark`.
- Status codes: 201, 204, 404, 409, 422. Correct.
- `ParkingResource` and `AvailabilityResource` shapes match the README.
- `bootstrap/app.php` converts framework `NotFoundHttpException` to JSON. Good.
- `ParkVehicleRequest` accepts any string ≤ 255 for `license_plate`. Defensible (plate formats vary internationally); a `/^[A-Z0-9-]+$/i` regex could be added for hygiene. Minor.

### README accuracy
- Endpoints, request/response shapes, error codes, project structure all match the implementation.
- Schema description matches the migration including denormalized `spots.parking_lot_id` and partial indexes.
- Allocation algorithm description matches `SpotAllocator`.
- Concurrency description matches the actual implementation (advisory lock + row lock + partial unique index + exception catch).
- Test groups summary matches `ParkingApiTest.php` (26 tests, 4 groups).
- Tradeoffs section is genuinely informative.

## References (web)

- [Laravel 13 new features (March 2026)](https://pola5h.github.io/blog/laravel-13-new-features/)
- [Laravel architecture best practices for 2026 — Benjamin Crozat](https://benjamincrozat.com/laravel-architecture-best-practices)
- [Eloquent Strict Mode — Amit Merchant](https://www.amitmerchant.com/eloquent-strict-mode-in-laravel/)
- [Local Model Scopes with #[Scope] — Laravel News](https://laravel-news.com/local-model-scopes-in-laravel-with-the-scope-attribute)
- [Eloquent: Getting Started — Laravel 13.x docs](https://laravel.com/docs/13.x/eloquent)
- [Understanding lockForUpdate and sharedLock in Laravel](https://rennokki.hashnode.dev/understanding-lockforupdate-and-sharedlock-in-laravel)
- [PostgreSQL advisory locks for concurrency control](https://www.kostolansky.sk/posts/postgresql-advisory-locks/)
- [Managing Data Races with Pessimistic Locking — Laravel News](https://laravel-news.com/managing-data-races-with-pessimistic-locking-in-laravel)
- [PHP 8.2 readonly classes + Laravel DTOs](https://dev.to/indunilperamuna/explore-the-advantages-of-data-transfer-objects-dtos-and-how-php-82-readonly-classes-can-elevate-your-laravel-code-4fk0)
