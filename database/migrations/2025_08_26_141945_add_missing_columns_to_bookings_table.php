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
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->after('driver_id');
            $table->decimal('pickup_latitude', 10, 8)->nullable()->after('pickup_location');
            $table->decimal('pickup_longitude', 11, 8)->nullable()->after('pickup_latitude');
            $table->decimal('dropoff_latitude', 10, 8)->nullable()->after('dropoff_location');
            $table->decimal('dropoff_longitude', 11, 8)->nullable()->after('dropoff_latitude');
            $table->decimal('distance', 8, 2)->nullable()->after('estimated_duration');
            $table->integer('duration')->nullable()->after('distance');
            $table->decimal('estimated_fare', 10, 2)->nullable()->after('total_amount');
            $table->decimal('final_fare', 10, 2)->nullable()->after('estimated_fare');
            $table->string('trip_code', 4)->nullable()->after('otp');
            $table->string('promo_usage_id')->nullable()->after('promo_code');

            // Add foreign key constraint
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
            $table->dropColumn([
                'city_id',
                'pickup_latitude',
                'pickup_longitude',
                'dropoff_latitude',
                'dropoff_longitude',
                'distance',
                'duration',
                'estimated_fare',
                'final_fare',
                'trip_code',
            ]);
        });
    }
};
