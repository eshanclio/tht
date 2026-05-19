<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_lots', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index('parking_lot_id');
        });

        // `spots.parking_lot_id` is denormalized from `sections.parking_lot_id`
        // for partial-index selectivity. The API does not move sections between
        // lots, so the two columns stay in sync. If section relocation is ever
        // added, this invariant must be enforced (trigger or app-level check).
        Schema::create('spots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('section_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('type');
            $table->integer('grid_row');
            $table->integer('grid_column');
            $table->foreignId('parking_id')
                ->nullable();
            $table->timestamps();

            $table->unique(['section_id', 'grid_row', 'grid_column']);
            $table->index('parking_lot_id');
        });

        // van_windows materializes every sliding-window candidate placement (3
        // consecutive car spots within a single (section, row)). Van allocation
        // is one atomic SELECT FOR UPDATE OF (window, 3 spots) SKIP LOCKED on
        // this table joined with spots — no advisory lock, no runtime scan.
        //
        // Coordinate-based overlap lookup contract: the up-to-3 windows that
        // include a car spot at (section_id, grid_row, grid_column = C) are
        // those with the same (section_id, grid_row) AND start_column BETWEEN
        // C-2 AND C. This is the only access pattern the application uses to
        // update blocked_count.
        //
        // blocked_count invariant (maintained by SpotAllocator):
        //   W.blocked_count == count(s in W's 3 underlying car spots:
        //                            s.parking_id IS NOT NULL
        //                            AND s.parking_id IS DISTINCT FROM W.parking_id)
        // Self-exclusion: a van does not block its own window — when W is
        // occupied by a van session P, all 3 spots carry P and the count is 0.
        //
        // Maintenance contract: only SpotAllocator, ParkVehicle, and
        // UnparkVehicle mutate this table. Any future writer of
        // spots.parking_id must call into SpotAllocator or take responsibility
        // for keeping blocked_count in sync. No triggers.
        Schema::create('van_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('section_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->integer('grid_row');
            $table->integer('start_column');
            $table->foreignId('car_spot_left_id')
                ->constrained('spots')
                ->cascadeOnDelete();
            $table->foreignId('car_spot_mid_id')
                ->constrained('spots')
                ->cascadeOnDelete();
            $table->foreignId('car_spot_right_id')
                ->constrained('spots')
                ->cascadeOnDelete();
            $table->foreignId('parking_id')
                ->nullable();
            $table->smallInteger('blocked_count')->default(0);
            $table->timestamps();

            $table->unique(['section_id', 'grid_row', 'start_column']);
            $table->index('parking_lot_id');
        });

        // Vehicles are scoped per lot so the same plate can independently exist
        // at two lots (real multi-tenancy). The composite unique closes the
        // "same plate twice in the same lot" hole.
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('license_plate');
            $table->string('type');
            $table->timestamps();

            $table->unique(['parking_lot_id', 'license_plate']);
        });

        // `parkings` carries `started_at`/`ended_at` for business semantics;
        // `created_at` is kept as a cheap insertion audit trail. No `updated_at`:
        // rows are only mutated to set `ended_at` once, and the model overrides
        // UPDATED_AT to null.
        Schema::create('parkings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('vehicle_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Circular references: spots.parking_id -> parkings.id and
        // van_windows.parking_id -> parkings.id. parkings is created last
        // (it depends on vehicles), so these FKs are added in a second pass.
        Schema::table('spots', function (Blueprint $table) {
            $table->foreign('parking_id')
                ->references('id')
                ->on('parkings')
                ->nullOnDelete();
        });

        Schema::table('van_windows', function (Blueprint $table) {
            $table->foreign('parking_id')
                ->references('id')
                ->on('parkings')
                ->nullOnDelete();
        });

        // Partial indexes: each index is scoped to the rows the allocator
        // queries against, keeping them small and selective.
        // `parking_id` is NULL for every free spot, so a partial index keeps
        // the unpark lookup (filter by parking_id) lean.
        DB::statement(
            'CREATE INDEX spots_parking_id_idx ON spots (parking_id)'
            .' WHERE parking_id IS NOT NULL'
        );
        DB::statement(
            'CREATE INDEX spots_available_car_lot_idx ON spots (parking_lot_id)'
            ." WHERE type = 'car' AND parking_id IS NULL"
        );

        // Hot index for the van allocator's candidate scan. The 4-way join
        // SELECT filters on (parking_id IS NULL AND blocked_count = 0); this
        // partial index reduces that scan to an index-only walk of allocatable
        // rows.
        DB::statement(
            'CREATE INDEX van_windows_available_idx ON van_windows (parking_lot_id, id)'
            .' WHERE parking_id IS NULL AND blocked_count = 0'
        );

        // Hard guarantee that at most one active session occupies a given
        // window; also serves the unpark lookup (VanWindow::where('parking_id', ...)).
        DB::statement(
            'CREATE UNIQUE INDEX van_windows_active_idx ON van_windows (parking_id)'
            .' WHERE parking_id IS NOT NULL'
        );

        // Closes the duplicate-active-parking race at the DB layer and
        // also serves the active-session lookup in UnparkVehicle (the
        // unique-on-vehicle_id index is sufficient; parking_lot_id is
        // re-checked from the heap, which is a single row).
        DB::statement(
            'CREATE UNIQUE INDEX parkings_active_vehicle_unique ON parkings (vehicle_id)'
            .' WHERE ended_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('van_windows', function (Blueprint $table) {
            $table->dropForeign(['parking_id']);
        });

        Schema::table('spots', function (Blueprint $table) {
            $table->dropForeign(['parking_id']);
        });

        Schema::dropIfExists('parkings');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('van_windows');
        Schema::dropIfExists('spots');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('parking_lots');
    }
};
