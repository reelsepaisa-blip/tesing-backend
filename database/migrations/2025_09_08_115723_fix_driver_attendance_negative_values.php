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
        // Fix existing negative values in driver_attendance table
        DB::statement('UPDATE driver_attendance SET total_online_seconds = 0 WHERE total_online_seconds < 0');
        DB::statement('UPDATE driver_attendance SET total_online_hours = 0.00 WHERE total_online_hours < 0');

        // Add constraints to prevent negative values in the future (skip sqlite ALTER CONSTRAINT limitations)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE driver_attendance ADD CONSTRAINT chk_total_online_seconds_positive CHECK (total_online_seconds >= 0)');
            DB::statement('ALTER TABLE driver_attendance ADD CONSTRAINT chk_total_online_hours_positive CHECK (total_online_hours >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE driver_attendance DROP CONSTRAINT IF EXISTS chk_total_online_seconds_positive');
            DB::statement('ALTER TABLE driver_attendance DROP CONSTRAINT IF EXISTS chk_total_online_hours_positive');
        }
    }
};
