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
        Schema::create('driver_ride_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('ride_type_id')->constrained('ride_types')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->json('meta_data')->nullable(); // For additional driver-specific ride type data
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['driver_id', 'is_active']);
            $table->index(['ride_type_id', 'is_active']);
            
            // Ensure unique driver-ride type combinations
            $table->unique(['driver_id', 'ride_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_ride_types');
    }
};
