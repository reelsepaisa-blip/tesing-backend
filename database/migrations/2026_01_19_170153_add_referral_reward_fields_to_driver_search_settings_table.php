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
        Schema::table('driver_search_settings', function (Blueprint $table) {
            $table->decimal('referrer_reward', 10, 2)->default(100.00)->after('round3_radius_km');
            $table->decimal('referred_reward', 10, 2)->default(100.00)->after('referrer_reward');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_search_settings', function (Blueprint $table) {
            $table->dropColumn(['referrer_reward', 'referred_reward']);
        });
    }
};
