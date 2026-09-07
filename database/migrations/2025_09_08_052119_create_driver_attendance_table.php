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
        Schema::create('driver_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('online_time');
            $table->timestamp('offline_time')->nullable();
            $table->integer('total_online_seconds')->nullable(); // Calculated when going offline
            $table->decimal('total_online_hours', 8, 2)->nullable(); // Calculated when going offline
            $table->date('date'); // Date for easier querying
            $table->json('meta_data')->nullable(); // For additional data like location, device info, etc.
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['driver_id', 'date']);
            $table->index(['driver_id', 'online_time']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_attendance');
    }
};
