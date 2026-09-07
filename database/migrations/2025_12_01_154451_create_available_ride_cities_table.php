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
        Schema::create('available_ride_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('city_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            // Ensure unique combination of ride_type_id and city_id
            $table->unique(['ride_type_id', 'city_id']);
            
            // Indexes for efficient querying
            $table->index('ride_type_id');
            $table->index('city_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('available_ride_cities');
    }
};
