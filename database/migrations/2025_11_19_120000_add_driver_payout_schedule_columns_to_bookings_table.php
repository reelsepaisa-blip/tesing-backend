<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'driver_payout_status')) {
                $table->string('driver_payout_status')->default('pending')->after('driver_amount');
            }

            if (!Schema::hasColumn('bookings', 'driver_payout_scheduled_at')) {
                $table->timestamp('driver_payout_scheduled_at')->nullable()->after('driver_payout_status');
            }

            if (!Schema::hasColumn('bookings', 'driver_payout_released_at')) {
                $table->timestamp('driver_payout_released_at')->nullable()->after('driver_payout_scheduled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'driver_payout_released_at')) {
                $table->dropColumn('driver_payout_released_at');
            }
            if (Schema::hasColumn('bookings', 'driver_payout_scheduled_at')) {
                $table->dropColumn('driver_payout_scheduled_at');
            }
            if (Schema::hasColumn('bookings', 'driver_payout_status')) {
                $table->dropColumn('driver_payout_status');
            }
        });
    }
};
