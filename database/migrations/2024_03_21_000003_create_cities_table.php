<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();

            // Length set to 100 to strictly restrict index byte size
            $table->string('name', 100);
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('India');

            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 10, 8);
            $table->boolean('status')->default(true);
            $table->string('timezone', 50)->default('Asia/Kolkata');
            $table->string('currency', 10)->default('INR');

            // Service Hours
            $table->time('service_start_time')->default('06:00:00');
            $table->time('service_end_time')->default('23:00:00');

            // Base Pricing
            $table->decimal('base_distance', 8, 2)->default(3.00);
            $table->decimal('base_price', 8, 2)->default(50.00);
            $table->decimal('price_per_km', 8, 2)->default(12.00);
            $table->decimal('price_per_minute', 8, 2)->default(2.00);
            $table->decimal('minimum_fare', 8, 2)->default(50.00);
            $table->decimal('cancellation_charge', 8, 2)->default(50.00);
            $table->decimal('waiting_charge_per_minute', 8, 2)->default(2.00);
            $table->integer('waiting_time_limit')->default(3);

            // Commission and Tax
            $table->decimal('commission_rate', 5, 2)->default(20.00);
            $table->decimal('tax_rate', 5, 2)->default(5.00);

            // Night Charges
            $table->decimal('night_charge_multiplier', 4, 2)->default(1.50);
            $table->time('night_start_time')->default('22:00:00');
            $table->time('night_end_time')->default('06:00:00');

            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['latitude', 'longitude']);

            // Replaced composite unique with separate index to fix 1000-byte limit permanently
            $table->index('name');
            $table->index('state');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cities');
    }
};
