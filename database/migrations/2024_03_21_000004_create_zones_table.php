<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Objects\Polygon;

return new class extends Migration
{
    public function up()
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('boundaries')->nullable();
            $table->boolean('status')->default(true);
            
            // Surge pricing
            $table->decimal('surge_multiplier', 4, 2)->default(1.00);
            $table->timestamp('surge_start_time')->nullable();
            $table->timestamp('surge_end_time')->nullable();
            
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Index
            if (DB::getDriverName() !== 'mysql') {
                $table->index('boundaries');
            }
        });

        // Create table for driver locations
        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('zone_id')->nullable()->constrained()->onDelete('set null');
            $table->text('location');
            $table->string('address')->nullable();
            $table->decimal('heading', 5, 2)->nullable(); // Driver's heading in degrees
            $table->boolean('is_active')->default(true);
            $table->timestamp('recorded_at');
            $table->timestamps();

            // Index
            if (DB::getDriverName() !== 'mysql') {
                $table->index('location');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE zones ADD INDEX zones_boundaries_index (boundaries(191))');
            DB::statement('ALTER TABLE driver_locations ADD INDEX driver_locations_location_index (location(191))');
        }
    }

    public function down()
    {
        Schema::dropIfExists('driver_locations');
        Schema::dropIfExists('zones');
    }
};
