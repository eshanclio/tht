# Parking Lot API

A Laravel 13 + PostgreSQL API for managing a multi-tenant parking lot system. Vehicles are parked, tracked, and released with strict spot-assignment rules and database-level concurrency guarantees.

---

## Quick Start

```bash
cp .env.example .env
composer install
php artisan migrate --seed
php artisan test
```

The seeder creates **Main Lot** with two sections (A and B), each containing 5 motorcycle spots (positions 1–5) and 10 car spots (positions 6–15) — 30 spots total.

---

## API Reference

All routes are prefixed with `/api`.

### Park a Vehicle

```
POST /api/parking-lots/{parkingLot}/sessions
```

The parking lot is identified in the URL path. Route model binding returns `404` if the lot does not exist.

| Field | Type | Required | Description |
|---|---|---|---|
| `license_plate` | string | yes | Vehicle identifier from the camera system |
| `vehicle_type` | string | yes | `motorcycle`, `car`, or `van` |

**Success `201`:**
```json
{
  "id": 1,
  "license_plate": "ABC-123",
  "vehicle_type": "car",
  "parking_lot_id": 1,
  "started_at": "2026-05-13T12:00:00.000000Z",
  "spots": [
    { "id": 12, "type": "car", "section_id": 1, "position": 6 }
  ]
}
```

**Errors:**
- `404` — parking lot not found
- `409` — no available spot, vehicle already parked, or vehicle type mismatch vs. its first-ever record
- `422` — validation failure (missing fields, unknown vehicle type)

### Unpark a Vehicle

```
DELETE /api/parking-lots/{parkingLot}/vehicles/{licensePlate}
```

Both the lot and the vehicle are identified in the URL. Route model binding returns `404` if the lot does not exist.

**Success `204 No Content`.** All assigned spots are freed atomically.

**Errors:**
- `404` — parking lot not found, vehicle not found, or not currently parked in this lot

### Lot Availability

```
GET /api/parking-lots/{parkingLot}/availability
```

**Success `200`:**
```json
{
  "total_motorcycle_spots": 10,
  "available_motorcycle_spots": 8,
  "total_car_spots": 20,
  "available_car_spots": 17,
  "total_capacity": 30,
  "total_available": 25,
  "available_van_spaces": 5
}
```

`available_van_spaces` counts non-overlapping groups of 3 consecutive free car spots, computed entirely in SQL.

---

## System Design

### Layered Architecture

```
HTTP Layer                   Domain Layer                    Persistence
────────────────────────     ──────────────────────────────  ─────────────────────
ParkVehicleRequest           ParkVehicleData (DTO)
       │                            │
ParkingController  ──────►  ParkVehicle (Action)  ──────►  SpotAllocator (Service)
       │                     UnparkVehicle                          │
       │                     GetLotAvailability                     ▼
       ▼                                                   Eloquent Models
ParkingResource                                            ──────────────────
AvailabilityResource                                       ParkingLot / Section
                                                           Spot / Vehicle
                                                           ParkingSession
```

- **HTTP layer** (`Http/Requests`, `Http/Controllers`, `Http/Resources`): validates input, hydrates DTOs, serializes responses. Zero business logic. Route model binding resolves `{parkingLot}` to a `ParkingLot` instance; `{licensePlate}` is a plain string URL segment passed directly to the action.
- **Actions** (`Domains/Parking/Actions`): one class per operation (`ParkVehicle`, `UnparkVehicle`, `GetLotAvailability`). Each action owns a single DB transaction and orchestrates services and models.
- **Services** (`Domains/Parking/Services`): `SpotAllocator` encapsulates all spot-selection logic. Two public methods: `allocate` (returns spot IDs to claim) and `countAvailableVanSpaces` (pure DB query for the availability endpoint).
- **Data** (`Domains/Parking/Data`): read-only DTOs (`ParkVehicleData`, `UnparkVehicleData`, `LotAvailabilityData`) and backed enums (`VehicleType`, `SpotType`).
- **Exceptions** (`Domains/Parking/Exceptions`): domain exceptions implement `render()` so Laravel resolves them directly to JSON responses without modifying any global exception handler.

### Database Schema

```
parking_lots
  id, name

sections
  id, parking_lot_id (FK → parking_lots)
  name

spots
  id
  parking_lot_id (FK → parking_lots)  ← denormalized from section (see Assumptions)
  section_id (FK → sections)
  type       VARCHAR ('motorcycle' or 'car', enforced at the application layer by SpotType enum casts)
  position   INTEGER                  ← ordinal within a section; consecutive = position differs by 1
  parking_id FK → parkings (nullable) ← NULL = available, non-null = occupied

vehicles
  id, parking_lot_id (FK → parking_lots)
  license_plate, type
  UNIQUE(parking_lot_id, license_plate)

parkings                               ← Eloquent model: ParkingSession
  id, parking_lot_id, vehicle_id
  started_at, ended_at (nullable)     ← ended_at NULL = active session
  UNIQUE(vehicle_id) WHERE ended_at IS NULL
```

**Circular FK:** `spots.parking_id → parkings.id`, but `spots` is created before `parkings` in the migration (because `parkings.vehicle_id` depends on `vehicles`, not on `spots`). The FK is added in a second `Schema::table` pass after `parkings` exists. The migration's `down()` drops this FK explicitly before dropping the tables.

**Partial indexes** (raw `DB::statement`) keep allocation queries small and selective:

| Index | Filter | Used by |
|---|---|---|
| `spots_available_car_section_idx` | `type='car' AND parking_id IS NULL` | Van consecutive-spot scan |
| `spots_available_car_lot_idx` | `type='car' AND parking_id IS NULL` | Car allocation |
| `parkings_active_vehicle_unique` (UNIQUE) | `ended_at IS NULL` | Duplicate-active-session guard at DB layer + active-session lookup on unpark |

Motorcycle allocation and per-lot availability counts are served by the plain `spots(parking_lot_id)` index — the motorcycle allocator query needs car rows as fallback, so a partial index scoped to motorcycle rows cannot serve it.

### Spot Assignment Rules

| Vehicle | Motorcycle Spot | Car Spot |
|---|---|---|
| Motorcycle | ✅ preferred | ✅ fallback when motorcycle spots are full |
| Car | ❌ | ✅ 1 spot |
| Van | ❌ | ✅ 3 consecutive spots, same section |

### Spot Allocation — `SpotAllocator`

**Motorcycle:** A single query orders available spots with `CASE WHEN type = 'motorcycle' THEN 0 ELSE 1 END` so motorcycle spots sort first. If none exist the next row is a car spot — no branching required.

**Car:** Straightforward `WHERE type = 'car' AND parking_id IS NULL ORDER BY id LIMIT 1` with a row-level lock.

**Van — consecutive-spot detection:**

1. Fetch all free car spots ordered by `section_id`, then `position`.
2. Group by `section_id`. Skip sections with fewer than 3 free spots.
3. Within each section, scan the sorted position list for an index `i` where `positions[i+2] - positions[i] == 2` (three consecutive integers).
4. The first match is the winning triplet. A follow-up `SELECT ... FOR UPDATE` on those three rows confirms they are still free before the `UPDATE`.

**Van space counting — gaps-and-islands SQL:**

`countAvailableVanSpaces` uses a three-level subquery to avoid loading rows into PHP:

```sql
-- Level 1: identify each consecutive run using the gaps-and-islands pattern.
-- Subtracting ROW_NUMBER() from position yields a constant value ("grp") for
-- spots that belong to the same uninterrupted run within a section.
SELECT section_id, position - ROW_NUMBER() OVER (PARTITION BY section_id ORDER BY position) AS grp
FROM spots WHERE type = 'car' AND parking_id IS NULL AND parking_lot_id = ?

-- Level 2: measure the length of each run.
SELECT COUNT(*) AS run_length FROM level1 GROUP BY section_id, grp

-- Level 3: a run of length L yields floor(L / 3) non-overlapping van slots.
SELECT COALESCE(SUM(FLOOR(run_length / 3)), 0) FROM level2
```

### Concurrency & Atomicity

**Vans** must atomically claim three consecutive spots. The implementation uses two layers:

1. **`pg_advisory_xact_lock(namespace, parking_lot_id)`** — Acquired at the start of every van park request within the transaction. This serializes all concurrent van requests for the same lot, ensuring only one transaction at a time performs the consecutive-spot scan and claims spots. The lock is transaction-scoped (released automatically on commit or rollback).
2. **`SELECT ... FOR UPDATE` on the three candidates** — Belt-and-braces confirmation that all three spots are still free immediately before the `UPDATE`. If any spot was taken between advisory lock acquisition and the row lock, a `NoAvailableSpotException` is raised — no partial reservation ever occurs.

Cars and motorcycles use only `SELECT ... FOR UPDATE` — single-spot allocation is inherently atomic.

**Duplicate-session prevention** is enforced at the database layer:

`UNIQUE INDEX parkings_active_vehicle_unique ON parkings(vehicle_id) WHERE ended_at IS NULL` guarantees at most one active session per vehicle. `ParkVehicle` does not perform an application-level pre-check — it relies entirely on this partial unique index. If a concurrent request attempts to insert a second active session for the same vehicle, the `INSERT` raises `UniqueConstraintViolationException`, which `ParkVehicle` catches inside the transaction (rolling back the vehicle upsert and spot reservation) and rethrows as `VehicleAlreadyParkedException` → `409`. Collapsing duplicate prevention to a single layer avoids an extra `SELECT ... FOR UPDATE` round-trip on every park request and removes a class of TOCTOU races that any application-level check would carry.

### Multi-Tenancy

`vehicles` has a `parking_lot_id` column and a `UNIQUE(parking_lot_id, license_plate)` constraint. Consequences:

- The same license plate at two different lots creates two independent `Vehicle` rows with independent types and histories.
- All allocation and session queries are scoped by `parking_lot_id` — no cross-lot data leaks.
- A vehicle's type can legitimately differ across lots (the test `test_same_plate_can_park_at_two_different_lots` exercises this).

---

## Assumptions

**Spot positions are dense integers within a section.** The seeder creates positions 1–15. "Consecutive" means `position[i+1] == position[i] + 1`. The allocator's gap detection (`positions[i+2] - positions[i] == 2`) relies on this integer ordering.

**Sections are never moved between lots.** `spots.parking_lot_id` is denormalized from `sections.parking_lot_id` to make partial indexes on spots selectively target a single lot. If section relocation were ever added, a trigger or app-level invariant check would be required to keep the two columns in sync. This is documented in the migration.

**Parking history is retained.** Sessions are closed by setting `ended_at`, never deleted. The unique partial index (`WHERE ended_at IS NULL`) scopes constraints to active rows only. Historical rows accumulate for potential analytics or billing use.

**No authentication or authorization.** Out of scope per the requirements.

**One active session per vehicle per lot.** A vehicle can have parallel active sessions at different lots (multi-tenancy), but attempting a second active session at the same lot returns `409`.

**Vehicle type is immutable after first park.** `createOrFirst` records the vehicle type on first contact. A subsequent request presenting the same plate with a different type raises `VehicleTypeMismatchException` (409). This prevents accidental reclassification caused by a misconfigured camera system.

**Fixed lot structure.** Dynamic lot/section/spot creation is not exposed via the API. The seeder populates one fixed lot.

---

## Tradeoffs

### Advisory lock for vans vs. pessimistic row-level locking

**Chosen:** `pg_advisory_xact_lock(namespace, lot_id)` + `SELECT ... FOR UPDATE` on the winning spots.

**Alternative:** `SELECT ... FOR UPDATE SKIP LOCKED` on all free car spots in the section, then detect the consecutive run inside the transaction. This avoids the advisory lock but holds row-level locks on many rows for longer, degrading throughput for concurrent car/motorcycle requests in the same section.

The advisory lock is lot-scoped and transaction-scoped — it serializes only van requests per lot and releases automatically on commit/rollback. Car and motorcycle row locks are unaffected.

### Denormalized `spots.parking_lot_id`

**Chosen:** Store `parking_lot_id` on every `Spot` row to make partial indexes on spots selectively target a single lot, avoiding a join through `sections`.

**Tradeoff:** `spots.parking_lot_id` and `sections.parking_lot_id` must stay in sync. This is acceptable because sections are never reassigned via the API. The migration documents this invariant.

### Spot occupancy tracked via a nullable FK on `spots`

**Chosen:** `spots.parking_id IS NULL` means available; a non-null value means occupied.

**Alternative A — a separate `spot_occupancies` join table:** Cleaner history per occupancy event, but the consecutive-spot scan becomes a left join and the availability query adds a subquery. Complexity not justified.

**Alternative B — a boolean `is_occupied` flag:** Duplicate state that can drift from the FK relationship. Rejected.

The nullable FK is self-consistent (enforced by the FK constraint), directly queryable, and requires no extra table.

### Parking history retained (soft-close via `ended_at`)

**Chosen:** Sessions are closed by setting `ended_at`; rows are never deleted.

**Alternative:** Delete the row on unpark. Simpler active-session constraint (no partial index needed), but loses query-able history. Retaining history is more useful for billing, auditing, and analytics in a real system.

### Domain exceptions implement `render()`

**Chosen:** Each domain exception declares `render(Request $request): JsonResponse`. Laravel's exception handler calls this automatically, keeping the response format co-located with the exception class.

**Alternative:** Register exception renderers in `withExceptions()` in `bootstrap/app.php`. This approach is used for the `NotFoundHttpException` handler (which catches model-binding failures and returns a clean `{"message": "ParkingLot not found."}` instead of a debug stack trace), since that exception originates from the framework rather than the domain. Domain exceptions still use `render()` for co-location; framework exceptions are handled centrally.

### Gaps-and-islands SQL for van space counting

**Chosen:** Three-level subquery using `ROW_NUMBER() OVER (PARTITION BY section_id ORDER BY position)` to identify runs, then `FLOOR(run_length / 3)` for non-overlapping van slots.

**Alternative:** Fetch all free car spots into PHP and compute in-process. Simpler to read, but transfers all rows across the wire and does O(n) work in the application tier. The SQL approach is a single round-trip and scales to large lots without memory pressure.

---

## Alternative Approaches Considered

**Event-driven unpark** — Emit a `VehicleUnparked` event and free spots in a listener. Useful if unparking triggers downstream effects (billing, notifications). Unnecessary indirection for this scope; the action handles it directly.

**Dedicated `SpotOccupancy` model** — Track occupancy in a separate table where each row represents one spot in one session. Cleaner history and easier audit trail, but every allocator query becomes a join and the consecutive-spot scan must carry the join through to the window function. Complexity not justified.

**Redis distributed lock for van concurrency** — Replace `pg_advisory_xact_lock` with a Redis `SET NX` or `Redlock`. Necessary only if multiple PostgreSQL primaries or external lock managers are required. For a single primary, the advisory lock is simpler and participates in the DB transaction lifecycle automatically.

**CQRS / pre-computed availability table** — Maintain a denormalized availability table updated by triggers or events. Eliminates the per-request aggregation query. Adds cache-invalidation complexity and eventual consistency that is not justified at this scale.

---

## Project Structure

```
src/
├── app/
│   ├── Domains/
│   │   └── Parking/
│   │       ├── Actions/           ParkVehicle, UnparkVehicle, GetLotAvailability
│   │       ├── Data/              DTOs + enums (VehicleType, SpotType, …)
│   │       ├── Exceptions/        Self-rendering domain exceptions
│   │       ├── Models/            ParkingLot, Section, Spot, Vehicle, ParkingSession
│   │       └── Services/          SpotAllocator
│   ├── Http/
│   │   ├── Controllers/           ParkingController
│   │   ├── Requests/              ParkVehicleRequest
│   │   └── Resources/             ParkingResource, AvailabilityResource
│   └── Providers/
│       └── AppServiceProvider.php
├── bootstrap/
│   └── app.php
├── database/
│   ├── migrations/
│   │   └── 2026_05_13_000001_create_parking_schema.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       └── ParkingLotSeeder.php
├── routes/
│   └── api.php
└── tests/
    └── Feature/
        └── ParkingApiTest.php     26 feature tests across 4 groups
```

### Test Coverage Groups

| Group | Scenarios |
|---|---|
| Golden path | Motorcycle spot, motorcycle fallback to car, car spot, van consecutive spots, unpark car, unpark van (all 3 spots freed) |
| Rejection / errors | Car blocked when only motorcycle spots remain, van rejected on fragmented spots, van rejected when < 3 spots exist, duplicate park, unpark unknown vehicle, unpark wrong lot |
| Validation | Missing license plate, invalid vehicle type, nonexistent lot (404), unknown vehicle in lot (404), type mismatch on re-park |
| State & availability | Capacity totals, occupied spot counts, van space reduction on occupancy, multi-tenancy isolation, full-lot rejection |
