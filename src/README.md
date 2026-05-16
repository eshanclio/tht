# Parking Lot Management System

A backend API for managing a multi-tenant parking lot. Built with **Laravel 13** and **PostgreSQL**, using a pragmatic Domain-Driven Design approach.

---

## What It Does

The system tracks available spots and manages vehicles as they park and depart. It supports three vehicle types with different spot requirements:

| Vehicle | Motorcycle Spot | Car Spot |
|---------|----------------|----------|
| **Motorcycle** | 1 spot | 1 spot (fallback) |
| **Car** | Not allowed | 1 spot |
| **Van** | Not allowed | 3 consecutive spots in the same section |

**Capabilities:**
- **Park** a vehicle by assigning the correct spot(s).
- **Unpark** a vehicle and free all associated spots.
- **Check availability** for any parking lot, including available van spaces (derived from consecutive car spots).

---

## Architecture

The code is organized into clear layers:

```
app/
├── Domains/Parking/
│   ├── Actions/          # Single-purpose use cases (Park, Unpark, Availability)
│   ├── Data/             # Enums (SpotType, VehicleType) + DTOs
│   ├── Models/           # Eloquent models
│   ├── Services/         # Domain logic (SpotAllocator)
│   └── Exceptions/       # Domain-specific errors
│
├── Http/
│   ├── Controllers/      # Thin controllers that delegate to Actions
│   ├── Requests/         # Form request validation
│   └── Resources/        # API response shaping
│
routes/api.php          # API route definitions
database/
├── migrations/           # Single combined migration
└── seeders/              # Fixed lot structure seeder
tests/Feature/            # Feature tests
```

**Why this structure?**
- **Actions as Services (AaaS)** — Each use case is a single invokable class with one public method. Controllers stay thin and only handle HTTP concerns.
- **Domain-first grouping** — Everything related to parking lives in one namespace. No need for repository interfaces because Eloquent is used directly.
- **DTOs for type safety** — `ParkVehicleData`, `UnparkVehicleData`, and `LotAvailabilityData` carry typed data between layers.

---

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/park` | Park a vehicle |
| `POST` | `/api/unpark` | Unpark a vehicle |
| `GET` | `/api/parking-lots/{id}/availability` | Get lot availability |

### Park a Vehicle
```json
POST /api/park
{
  "license_plate": "ABC-123",
  "vehicle_type": "car",
  "parking_lot_id": 1
}
```

**Success response (201):**
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

**Error responses:**
- `409` — No available spot, or vehicle is already parked.
- `400` — Validation error (missing fields, invalid vehicle type, type mismatch for known vehicle).

### Unpark a Vehicle
```json
POST /api/unpark
{
  "license_plate": "ABC-123",
  "parking_lot_id": 1
}
```

**Success response:** `204 No Content`

**Error responses:**
- `409` — Vehicle is not currently parked in this lot.
- `400` — Validation error.

### Check Availability
```
GET /api/parking-lots/1/availability
```

**Response:**
```json
{
  "total_motorcycle_spots": 10,
  "available_motorcycle_spots": 10,
  "total_car_spots": 20,
  "available_car_spots": 20,
  "total_capacity": 30,
  "total_available": 30,
  "available_van_spaces": 6
}
```

`available_van_spaces` counts how many groups of 3 consecutive car spots exist per section. A van occupies one van space.

---

## Domain Model

### Entities

- **ParkingLot** — A parking lot (multi-tenant support).
- **Section** — A row/section within a lot (e.g., "A", "B").
- **Spot** — An individual parking spot. Type is `motorcycle` or `car`. Occupancy is tracked via `parking_id` (nullable FK). No separate `is_occupied` column or pivot table.
- **Vehicle** — Identified by license plate. Type is `motorcycle`, `car`, or `van`.
- **Parking** — A parking session with `started_at` and `ended_at`. When `ended_at` is `null`, the vehicle is currently parked.

### Relationships

- `ParkingLot` has many `Section`s and `Parking`s.
- `Section` belongs to a `ParkingLot` and has many `Spot`s.
- `Spot` belongs to a `Section` and optionally belongs to a `Parking`.
- `Parking` belongs to a `ParkingLot` and a `Vehicle`, and has many `Spot`s.
- `Vehicle` has many `Parking`s.

---

## Spot Allocation Algorithm

The `SpotAllocator` service decides which spot(s) to assign.

### Motorcycle
1. Try to find an available `motorcycle` spot.
2. If none, fall back to an available `car` spot.
3. If still none, reject.

### Car
1. Find any available `car` spot.
2. If none, reject (motorcycle spots are not allowed).

### Van
1. Query all available `car` spots, ordered by `section_id` then `position`.
2. Group by section.
3. Within each section, scan for 3 consecutive positions (gaps-and-islands).
4. If found, lock and allocate all 3 spots atomically.
5. If no section has 3 consecutive spots, reject.

All allocation queries use `lockForUpdate()` inside a `DB::transaction()` to prevent race conditions between concurrent park requests.

---

## Database Schema

A single migration creates all tables in dependency order:

1. `parking_lots` — `id`, `name`
2. `sections` — `id`, `parking_lot_id`, `name`
3. `spots` — `id`, `parking_lot_id`, `section_id`, `type`, `position`, `parking_id`
4. `vehicles` — `id`, `license_plate`, `type`
5. `parkings` — `id`, `parking_lot_id`, `vehicle_id`, `started_at`, `ended_at`

The `spots.parking_id` foreign key is added **after** `parkings` is created (deferred FK) to respect dependency order.

### Schema Diagram (DBML)

```dbml
Table parking_lots {
  id bigint [pk, increment]
  name varchar
  created_at timestamp
  updated_at timestamp
}

Table sections {
  id bigint [pk, increment]
  parking_lot_id bigint [not null, ref: > parking_lots.id]
  name varchar
  created_at timestamp
  updated_at timestamp
}

Table spots {
  id bigint [pk, increment]
  parking_lot_id bigint [not null, ref: > parking_lots.id]
  section_id bigint [not null, ref: > sections.id]
  type varchar [not null, note: "'motorcycle' or 'car'"]
  position int [not null]
  parking_id bigint [nullable, ref: > parkings.id]
  created_at timestamp
  updated_at timestamp

  indexes {
    (section_id, position) [unique]
    parking_lot_id
  }
}

Table vehicles {
  id bigint [pk, increment]
  license_plate varchar [unique, not null]
  type varchar [not null, note: "'motorcycle', 'car', or 'van'"]
  created_at timestamp
  updated_at timestamp
}

Table parkings {
  id bigint [pk, increment]
  parking_lot_id bigint [not null, ref: > parking_lots.id]
  vehicle_id bigint [not null, ref: > vehicles.id]
  started_at timestamp [not null]
  ended_at timestamp [nullable, note: "null = active parking"]
}
```

### PostgreSQL Partial Indexes

Four partial indexes are created with raw `DB::statement()` for performance:

| Index | Purpose |
|-------|---------|
| `spots_available_car_section_idx` | Van allocation — available car spots by section, ordered |
| `spots_available_motorcycle_lot_idx` | Motorcycle allocation — available motorcycle spots by lot |
| `spots_available_car_lot_idx` | Car allocation + motorcycle fallback |
| `parkings_active_idx` | Active parking lookup by vehicle and lot |

Partial indexes are smaller and faster because they only index rows that matter for allocation queries.

---

## Concurrency & Atomicity

- **`lockForUpdate()`** — Used on all allocation queries. PostgreSQL blocks concurrent transactions until the lock holder commits, then the waiting transaction re-evaluates the query against committed data.
- **`DB::transaction()`** — Wraps the entire park/unpark operation. Either all spot updates succeed or none do.
- **`createOrFirst()`** — Race-condition-safe vehicle creation (INSERT-first, SELECT on unique violation). Better than `firstOrCreate()` for concurrent environments.

---

## Seeding

`ParkingLotSeeder` creates a fixed lot structure:
- Lot: "Main Lot"
- Sections: "A", "B"
- Each section: 5 motorcycle spots (positions 1-5) + 10 car spots (positions 6-15)
- Total: 30 spots (10 motorcycle, 20 car)

---

## Testing

Feature tests cover all core scenarios:

- Motorcycle parks in a motorcycle spot
- Motorcycle falls back to a car spot when motorcycle spots are full
- Car parks in a car spot
- Van parks in 3 consecutive car spots
- Unpark frees associated spots
- Car rejected when only motorcycle spots remain
- Van rejected when car spots are fragmented
- Van rejected when fewer than 3 car spots exist
- Park fails when vehicle is already parked
- Unpark fails when vehicle is not parked
- Validation errors (missing fields, invalid types)
- Vehicle type mismatch for known vehicle
- Availability reflects total capacity and occupied spots
- Van occupancy reduces available van spaces
- Multi-tenancy (parking lot isolation)

**Run tests:**
```bash
make test
```

All 21 tests pass with 57 assertions.

---

## Development Environment

This project runs inside Docker. No local PHP/PostgreSQL/Redis installation is required.

**Prerequisites:** Docker, Docker Compose, Make

**Setup:**
```bash
cp .env.example .env
make up       # Build and start services
make key      # Generate APP_KEY
make migrate  # Run migrations
make seed     # Seed the database
```

**Common commands:**
```bash
make test         # Run PHPUnit
make fresh        # Fresh migrate + seed
make artisan CMD="..."   # Run any Artisan command
make composer CMD="..."  # Run any Composer command
```

App is available at `http://localhost:8000`.
