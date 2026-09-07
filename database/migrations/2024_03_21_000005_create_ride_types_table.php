<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ride_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->integer('capacity')->default(4);
            $table->boolean('status')->default(true);
            $table->integer('order')->default(0);
            
            // Default pricing (can be overridden by city)
            $table->decimal('base_distance', 8, 2)->default(3.00); // in km
            $table->decimal('base_price', 8, 2)->default(50.00);
            $table->decimal('price_per_km', 8, 2)->default(12.00);
            $table->decimal('price_per_minute', 8, 2)->default(2.00);
            $table->decimal('minimum_fare', 8, 2)->default(50.00);
            $table->decimal('cancellation_charge', 8, 2)->default(50.00);
            $table->decimal('waiting_charge_per_minute', 8, 2)->default(2.00);
            $table->integer('waiting_time_limit')->default(3); // in minutes
            $table->decimal('commission_rate', 5, 2)->default(20.00); // percentage
            
            // Requirements
            $table->json('driver_requirements')->nullable();
            $table->json('vehicle_requirements')->nullable();
            
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('code');
            $table->index(['status', 'order']);
        });

        // Create pivot table for city-specific ride type pricing
        Schema::create('city_ride_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->onDelete('cascade');
            $table->foreignId('ride_type_id')->constrained()->onDelete('cascade');
            
            // City-specific pricing
            $table->decimal('base_distance', 8, 2);
            $table->decimal('base_price', 8, 2);
            $table->decimal('price_per_km', 8, 2);
            $table->decimal('price_per_minute', 8, 2);
            $table->decimal('minimum_fare', 8, 2);
            $table->decimal('cancellation_charge', 8, 2);
            $table->decimal('waiting_charge_per_minute', 8, 2);
            $table->integer('waiting_time_limit');
            $table->decimal('commission_rate', 5, 2);
            $table->boolean('status')->default(true);
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['city_id', 'ride_type_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('city_ride_types');
        Schema::dropIfExists('ride_types');
    }
};








