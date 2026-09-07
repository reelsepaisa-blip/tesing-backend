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
        // First, add the new columns
        Schema::table('driver_search_settings', function (Blueprint $table) {
            // Check if old columns exist to determine placement
            if (Schema::hasColumn('driver_search_settings', 'referrer_reward')) {
                // Add new driver columns after existing columns
                $table->decimal('driver_referrer_reward', 10, 2)->default(100.00)->after('referred_reward');
                $table->decimal('driver_referred_reward', 10, 2)->default(100.00)->after('driver_referrer_reward');
            } else {
                // If old columns don't exist, add after round3_radius_km
                $table->decimal('driver_referrer_reward', 10, 2)->default(100.00)->after('round3_radius_km');
                $table->decimal('driver_referred_reward', 10, 2)->default(100.00)->after('driver_referrer_reward');
            }
            
            // Add user-specific fields
            $table->decimal('user_referrer_reward', 10, 2)->default(100.00)->after('driver_referred_reward');
            $table->decimal('user_referred_reward', 10, 2)->default(100.00)->after('user_referrer_reward');
        });
        
        // Then, copy existing values if old columns exist
        if (Schema::hasColumn('driver_search_settings', 'referrer_reward')) {
            \DB::statement('UPDATE driver_search_settings SET driver_referrer_reward = COALESCE(referrer_reward, 100.00), driver_referred_reward = COALESCE(referred_reward, 100.00)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_search_settings', function (Blueprint $table) {
            // Add back original columns if rolling back
            $table->decimal('referrer_reward', 10, 2)->default(100.00)->after('round3_radius_km');
            $table->decimal('referred_reward', 10, 2)->default(100.00)->after('referrer_reward');
            
            // Copy driver values back to original columns
            \DB::statement('UPDATE driver_search_settings SET referrer_reward = driver_referrer_reward, referred_reward = driver_referred_reward');
            
            // Drop new columns
            $table->dropColumn([
                'driver_referrer_reward',
                'driver_referred_reward',
                'user_referrer_reward',
                'user_referred_reward'
            ]);
        });
    }
};
