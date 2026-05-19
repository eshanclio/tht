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

The seeder creates **Main Lot** with two sections (A and B), each a 3×5 grid. Row 1 of each section holds 5 motorcycle spots; rows 2 and 3 hold 5 car spots each — 30 spots total. The seeder also materializes **12 van candidate windows** (3 sliding-window placements per car row × 4 car rows), used by the van allocator's single-statement candidate pick.

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
    { "id": 12, "type": "car", "section_id": 1, "grid_row": 2, "grid_column": 1 }
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
  "available_van_spaces": 4
}
```

`available_van_spaces` counts non-overlapping groups of 3 consecutive free car spots **within a single (section, row)**, computed entirely in SQL.

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
  grid_row    INTEGER                  ← ordinal row within a section
  grid_column INTEGER                  ← ordinal column within a (section, row); consecutive = differs by 1
  parking_id FK → parkings (nullable) ← NULL = available, non-null = occupied

van_windows
  id
  parking_lot_id (FK → parking_lots)
  section_id (FK → sections)
  grid_row    INTEGER
  start_column INTEGER             ← leftmost car spot's grid_column
  car_spot_left_id  FK → spots
  car_spot_mid_id   FK → spots
  car_spot_right_id FK → spots
  parking_id FK → parkings (nullable) ← NULL = available, non-null = van occupies this window
  blocked_count SMALLINT NOT NULL  ← count of W's car spots taken by OTHER sessions
  UNIQUE(section_id, grid_row, start_column)

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
| `van_windows_available_idx` on `(parking_lot_id, id)` | `parking_id IS NULL AND blocked_count = 0` | Van allocator candidate scan |
| `van_windows_active_idx` UNIQUE on `(parking_id)` | `parking_id IS NOT NULL` | Active van session lookup; one-session-per-window guarantee |
| `spots_available_car_lot_idx` on `(parking_lot_id)` | `type='car' AND parking_id IS NULL` | Car allocation |
| `spots_parking_id_idx` on `(parking_id)` | `parking_id IS NOT NULL` | Unpark: locate all spots belonging to a session |
| `parkings_active_vehicle_unique` UNIQUE on `(vehicle_id)` | `ended_at IS NULL` | Duplicate-active-session guard |

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

**Van — single-statement candidate pick:**

Van allocation is one atomic 4-way join with `FOR UPDATE OF w, sl, sm, sr SKIP LOCKED LIMIT 1`. The candidate `van_windows` row is locked together with its 3 underlying spots; any contended row causes the candidate to be skipped. No advisory lock, no retry loop in the allocator.

```sql
SELECT w.id, w.section_id, w.grid_row, w.start_column,
       w.car_spot_left_id, w.car_spot_mid_id, w.car_spot_right_id
FROM   van_windows w
JOIN   spots sl ON sl.id = w.car_spot_left_id
JOIN   spots sm ON sm.id = w.car_spot_mid_id
JOIN   spots sr ON sr.id = w.car_spot_right_id
WHERE  w.parking_lot_id = ?
  AND  w.parking_id IS NULL
  AND  w.blocked_count = 0
  AND  sl.parking_id IS NULL
  AND  sm.parking_id IS NULL
  AND  sr.parking_id IS NULL
ORDER  BY w.id
LIMIT  1
FOR UPDATE OF w, sl, sm, sr SKIP LOCKED;
```

`blocked_count` is a denormalized counter on each `van_windows` row, maintained by `SpotAllocator` inside the same transaction as `spots.parking_id` updates. Invariant:

```
W.blocked_count == count(s in W's 3 underlying car spots:
                         s.parking_id IS NOT NULL
                         AND s.parking_id IS DISTINCT FROM W.parking_id)
```

When a car (or motorcycle-fallback-to-car) takes a spot, `SpotAllocator::bumpBlockedCountForCarSpot` increments the up-to-3 overlapping windows by coordinate (`section_id = ? AND grid_row = ? AND start_column BETWEEN ?-2 AND ?`). When a van parks at window W, the same bump runs for each of W's 3 spots with `excludeWindowId = W.id` so W itself stays at `blocked_count = 0`. Unpark reverses each step.

**Van space counting — read-time gaps-and-islands on `van_windows`:**

`countAvailableVanSpaces` returns the actual number of non-overlapping vans that can park right now, computed live on each call. No counter is persisted. The query runs on `van_windows` filtered by the partial index (`parking_id IS NULL AND blocked_count = 0`):

```sql
-- Stage 1: identify consecutive chains of available windows per (section, row).
SELECT section_id, grid_row,
       start_column - ROW_NUMBER() OVER (
           PARTITION BY section_id, grid_row ORDER BY start_column
       ) AS grp
FROM   van_windows
WHERE  parking_lot_id = ? AND parking_id IS NULL AND blocked_count = 0;

-- Stage 2: measure chain length.
SELECT COUNT(*) AS run_length FROM stage1 GROUP BY section_id, grid_row, grp;

-- Stage 3: a chain of L available windows = L+2 free car spots = floor((L+2)/3) vans.
SELECT COALESCE(SUM(FLOOR((run_length + 2) / 3)), 0) AS total FROM stage2;
```

### Concurrency & Atomicity

**Vans** allocate via one atomic statement that locks the candidate window and its 3 underlying spots together (`SELECT … FOR UPDATE OF w, sl, sm, sr SKIP LOCKED LIMIT 1`). Concurrent vans pick different windows because `SKIP LOCKED` makes the second pick observe the first's lock and move on. Concurrent cars/motorcycles never block vans (each is a separate `FOR UPDATE SKIP LOCKED` on its own row), and vice versa.

**Cars and motorcycles** use single-row `FOR UPDATE SKIP LOCKED` picks. Motorcycles use a `CASE WHEN type='motorcycle'` ordering so they prefer motorcycle spots and fall back to car spots automatically.

**`blocked_count` neighbor UPDATEs.** After the SKIP-LOCKED pick, every park/unpark updates the up-to-3 overlapping `van_windows` rows by coordinate. Two concurrent vans in nearby windows (`|S₁ − S₂| ≤ 2` in the same `(section, row)`) can deadlock here — Postgres detects the cycle (SQLSTATE 40P01) and aborts the loser. `ParkVehicle::handle` and `UnparkVehicle::handle` use `DB::transaction($callback, attempts: 3)` so Laravel re-runs the transaction automatically; the SKIP-LOCKED re-scan naturally avoids the contested window on retry.

**Duplicate-session prevention** is unchanged: the partial unique index `parkings_active_vehicle_unique` raises `UniqueConstraintViolationException` on duplicate INSERTs, which `ParkVehicle` catches and rethrows as `VehicleAlreadyParkedException`.

### Multi-Tenancy

`vehicles` has a `parking_lot_id` column and a `UNIQUE(parking_lot_id, license_plate)` constraint. Consequences:

- The same license plate at two different lots creates two independent `Vehicle` rows with independent types and histories.
- All allocation and session queries are scoped by `parking_lot_id` — no cross-lot data leaks.
- A vehicle's type can legitimately differ across lots (the test `test_same_plate_can_park_at_two_different_lots` exercises this).

---

## Assumptions

**Spots live on a 2D grid within a section.** Each spot has `(grid_row, grid_column)` integer coordinates unique within its section. "Consecutive" for a van means three columns differing by exactly 1 each within a single `(section, grid_row)`. A missing `(section, grid_row, grid_column)` triple is an implicit aisle / non-parking cell.

**Sections are never moved between lots.** `spots.parking_lot_id` is denormalized from `sections.parking_lot_id` to make partial indexes on spots selectively target a single lot. If section relocation were ever added, a trigger or app-level invariant check would be required to keep the two columns in sync. This is documented in the migration.

**Parking history is retained.** Sessions are closed by setting `ended_at`, never deleted. The unique partial index (`WHERE ended_at IS NULL`) scopes constraints to active rows only. Historical rows accumulate for potential analytics or billing use.

**No authentication or authorization.** Out of scope per the requirements.

**One active session per vehicle per lot.** A vehicle can have parallel active sessions at different lots (multi-tenancy), but attempting a second active session at the same lot returns `409`.

**Vehicle type is immutable after first park.** `createOrFirst` records the vehicle type on first contact. A subsequent request presenting the same plate with a different type raises `VehicleTypeMismatchException` (409). This prevents accidental reclassification caused by a misconfigured camera system.

**Fixed lot structure.** Dynamic lot/section/spot creation is not exposed via the API. The seeder populates one fixed lot.

---

## Tradeoffs

### Pre-materialized candidate table vs. runtime gaps-and-islands

**Chosen:** Materialize every sliding-window van placement as a row in `van_windows`. The allocator's hot path is one atomic `SELECT … FOR UPDATE OF w, sl, sm, sr SKIP LOCKED LIMIT 1`. `blocked_count` is a denormalized counter maintained by `SpotAllocator` on park/unpark.

**Why not runtime gaps-and-islands on `spots`:** The original implementation did exactly that, plus a `pg_advisory_xact_lock` to serialize vans on the same lot. Throughput was throttled by the lot-scoped advisory lock even when concurrent vans would have targeted disjoint windows. The two-step scan-then-lock had a race that the advisory lock papered over rather than structurally eliminating.

**Costs:**

- Write amplification — every park/unpark of a car spot UPDATEs up to 3 overlapping `van_windows` rows; every van park/unpark UPDATEs up to 4 neighbor rows for `blocked_count`. Bounded and small.
- New denormalized invariant (`blocked_count`) maintained by application code, not by DB triggers. Defended by a property-style invariant test that runs after every operation in a randomized park/unpark sequence.
- A cross-class deadlock surface during the neighbor UPDATE phase; absorbed by `DB::transaction(..., attempts: 3)`.

**Won't catch on its own:** any future writer of `spots.parking_id` that bypasses `SpotAllocator` would drift `blocked_count`. Documented in the migration header and enforced by the test suite.

#### `blocked_count` invariant

`blocked_count` is application-maintained denormalized state, not a DB-enforced constraint. It is the load-bearing column that makes the SKIP-LOCKED candidate pick a true O(1)-on-the-index lookup: the partial index `van_windows_available_idx` filters on `parking_id IS NULL AND blocked_count = 0`, so the allocator never reads a window whose underlying spots are taken. The invariant is verified by a property test that issues a randomized sequence of park/unpark operations and after every step asserts, for every window W, that `W.blocked_count` equals the count of W's underlying car spots whose `parking_id` is set to some session other than W's own.

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

### 2D adjacency restricted to horizontal (same row)

**Chosen:** A van needs three consecutive `grid_column` values within a single `(section_id, grid_row)`.

**Alternative:** Allow vertical (three rows, same column) or L-shape (graph-search across grid-adjacent cells). Rejected — vertical adjacency is physically nonsensical for nose-in parking (the car in row N+1 blocks egress for the car in row N), and L/T-shapes can't fit a single rigid van.

The horizontal-only constraint also keeps the allocator a single index-ordered scan plus a constant-time check (`columns[i+2] - columns[i] == 2`), and lets the availability count stay a one-round-trip gaps-and-islands SQL by adding one column (`grid_row`) to the `PARTITION BY`.

### Gaps-and-islands SQL for van space counting

**Chosen:** Three-stage subquery over `van_windows` using `ROW_NUMBER() OVER (PARTITION BY section_id, grid_row ORDER BY start_column)` to identify consecutive chains of available windows, then `FLOOR((run_length + 2) / 3)` for non-overlapping van slots.

**Alternative:** Fetch the candidate rows into PHP and compute in-process. Simpler to read, but transfers rows across the wire and does O(n) work in the application tier. The SQL approach is a single round-trip and scales to large lots without memory pressure.

---

## Alternative Approaches Considered

**Event-driven unpark** — Emit a `VehicleUnparked` event and free spots in a listener. Useful if unparking triggers downstream effects (billing, notifications). Unnecessary indirection for this scope; the action handles it directly.

**Dedicated `SpotOccupancy` model** — Track occupancy in a separate table where each row represents one spot in one session. Cleaner history and easier audit trail, but every allocator query becomes a join and the consecutive-spot scan must carry the join through to the window function. Complexity not justified.

**Redis distributed lock for van concurrency** — Replace per-lot serialization with a Redis `SET NX` or `Redlock`. Necessary only if multiple PostgreSQL primaries or external lock managers are required. For a single primary, structural elimination via `SKIP LOCKED` on the pre-materialized `van_windows` table is simpler and stays inside the DB transaction lifecycle.

**CQRS / pre-computed availability table** — The `van_windows` table is exactly this pattern, scoped to van candidate placements rather than full lot availability. It pays its way because the van allocator's hot path collapses to a single index seek + lock. A broader availability projection (per-lot counters maintained by triggers) was rejected: motorcycle / car counts are already cheap aggregates, and a full counter table would add cache-invalidation complexity without removing a hot query.

---

## Project Structure

```
src/
├── app/
│   ├── Domains/
│   │   └── Parking/
│   │       ├── Actions/           ParkVehicle, UnparkVehicle, GetLotAvailability
│   │       ├── Data/              DTOs + enums (VehicleType, SpotType, AllocationResult, …)
│   │       ├── Exceptions/        Self-rendering domain exceptions
│   │       ├── Models/            ParkingLot, Section, Spot, Vehicle, ParkingSession, VanWindow
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
        └── ParkingApiTest.php     feature tests across 5 groups (see Test Coverage Groups)
```

### Test Coverage Groups

| Group | Scenarios |
|---|---|
| Golden path | Motorcycle spot, motorcycle fallback to car, car spot, van consecutive spots (start / middle / end-of-row windows), van horizontal-run in single row, van picks only valid row when others fragmented, van section-preference (lower section\_id wins), van column-sort correctness (spots inserted in non-ascending order), unpark car, unpark van (all 3 spots freed) |
| Rejection / errors | Car blocked when only motorcycle spots remain, van rejected on fragmented spots, van rejected when < 3 spots exist, duplicate park, unpark unknown vehicle, unpark wrong lot, van rejected at row boundary, van rejected on aisle split |
| Validation | Missing license plate, invalid vehicle type, nonexistent lot (404), type mismatch on re-park, same plate at two different lots (multi-tenancy), park response includes grid coordinates |
| State & availability | Capacity totals, occupied spot counts, van space reduction on occupancy, multi-tenancy isolation, full-lot rejection, availability 404 for nonexistent lot, vertical adjacency does not count as van slot |
| Sliding-window invariants | seeder integrity (12 windows, correct geometry, idempotent), `blocked_count` invariant after arbitrary park/unpark, motorcycle fallback bumps blocked_count, unpark van resets blocked_count, van does not block its own window, van skips window with blocked spot, 409 when all windows blocked, availability matches independent greedy after sequence |
