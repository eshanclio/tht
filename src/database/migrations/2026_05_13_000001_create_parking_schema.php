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
            $table->integer('position');
            $table->foreignId('parking_id')
                ->nullable();
            $table->timestamps();

            $table->unique(['section_id', 'position']);
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

        // Circular reference: spots.parking_id -> parkings.id, but spots is
        // created before parkings (parkings carries the parking_lot_id FK).
        // Note: spots cascade-delete from parking_lots already, so the
        // nullOnDelete here only matters for the unpark flow.
        Schema::table('spots', function (Blueprint $table) {
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
            'CREATE INDEX spots_available_car_section_idx ON spots (section_id, position)'
            ." WHERE type = 'car' AND parking_id IS NULL"
        );
        DB::statement(
            'CREATE INDEX spots_available_car_lot_idx ON spots (parking_lot_id)'
            ." WHERE type = 'car' AND parking_id IS NULL"
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
        Schema::table('spots', function (Blueprint $table) {
            $table->dropForeign(['parking_id']);
        });

        Schema::dropIfExists('parkings');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('spots');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('parking_lots');
    }
};
