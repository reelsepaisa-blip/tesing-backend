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
        Schema::table('cancellation_policies', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->foreignId('ride_type_id')->nullable()->after('city_id')->constrained()->onDelete('cascade');

            // Add index for faster lookups
            $table->index(['city_id', 'ride_type_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cancellation_policies', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
            $table->dropForeign(['ride_type_id']);
            $table->dropIndex(['city_id', 'ride_type_id', 'is_active']);
            $table->dropColumn(['city_id', 'ride_type_id']);
        });
    }
};
