<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
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

		Schema::table("spots", function (Blueprint $table) {
			$table->foreign("parking_id")
				->references("id")
				->on("parkings")
				->nullOnDelete();
		});

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

		DB::statement(
			"CREATE UNIQUE INDEX parkings_active_vehicle_unique ON parkings (vehicle_id)"
			." WHERE ended_at IS NULL"
		);
	}

	/**
	 * Reverse the migrations.
	 */
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
