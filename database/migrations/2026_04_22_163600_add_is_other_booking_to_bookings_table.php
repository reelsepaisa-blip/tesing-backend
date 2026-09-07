<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('bookings', 'is_other_booking')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->boolean('is_other_booking')->default(false)->after('passenger_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'is_other_booking')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('is_other_booking');
            });
        }
    }
};
