<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('driver_locations', function (Blueprint $table) {
            // Add missing columns for location tracking
            $table->decimal('speed', 8, 2)->nullable()->after('heading')->comment('Speed in km/h');
            $table->decimal('accuracy', 8, 2)->nullable()->after('speed')->comment('Location accuracy in meters');
            $table->integer('battery_level')->nullable()->after('accuracy')->comment('Battery level percentage (0-100)');
            $table->boolean('is_charging')->default(false)->after('battery_level')->comment('Whether device is charging');

            // Add index for better performance
            $table->index(['driver_id', 'is_active', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_locations', function (Blueprint $table) {
            // Drop the added columns
            $table->dropColumn(['speed', 'accuracy', 'battery_level', 'is_charging']);

            // Drop the index
            $table->dropIndex(['driver_id', 'is_active', 'recorded_at']);
        });
    }
};
