<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('driver_locations', function (Blueprint $table) {
            // Add new latitude and longitude columns
            $table->decimal('latitude', 10, 8)->nullable()->after('location');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');

            // Add index for better performance on location queries
            $table->index(['latitude', 'longitude'], 'driver_locations_lat_lon_index');
        });

        // Migrate existing POINT data to lat/lon columns
        $this->migrateExistingData();

        try {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE driver_locations DROP INDEX driver_locations_location_index');
            } else {
                DB::statement('DROP INDEX IF EXISTS driver_locations_location_index');
            }
        } catch (\Throwable $e) {
            // Ignore when index does not exist on some environments.
        }

        // Drop the old location column after migration
        Schema::table('driver_locations', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_locations', function (Blueprint $table) {
            // Recreate the location column
            $table->text('location')->nullable()->after('zone_id');

            // Drop the new columns
            $table->dropColumn(['latitude', 'longitude']);

            // Drop the index
            $table->dropIndex('driver_locations_lat_lon_index');
        });
    }

    /**
     * Migrate existing POINT data to lat/lon columns
     */
    private function migrateExistingData(): void
    {
        $driverLocations = DB::table('driver_locations')->whereNotNull('location')->get();

        foreach ($driverLocations as $location) {
            // Parse POINT(longitude latitude) format
            if (preg_match('/POINT\(([^)]+)\)/', $location->location, $matches)) {
                $coordinates = explode(' ', $matches[1]);
                if (count($coordinates) >= 2) {
                    $longitude = (float) $coordinates[0];
                    $latitude = (float) $coordinates[1];

                    // Update the record with lat/lon
                    DB::table('driver_locations')
                        ->where('id', $location->id)
                        ->update([
                            'latitude' => $latitude,
                            'longitude' => $longitude
                        ]);
                }
            }
        }
    }
};
