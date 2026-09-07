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
        Schema::create('driver_incentives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->string('title');
            $table->text('description');
            $table->enum('type', ['ride_count', 'streak', 'time_based', 'earnings', 'custom']);
            $table->json('criteria'); // Flexible criteria based on type
            $table->decimal('reward_amount', 10, 2);
            $table->enum('status', ['upcoming', 'live', 'completed', 'expired', 'cancelled']);
            $table->datetime('start_time');
            $table->datetime('end_time');
            $table->json('milestones')->nullable(); // Array of milestone objects
            $table->json('zones')->nullable(); // Applicable zones/cities
            $table->json('ride_types')->nullable(); // Applicable ride types
            $table->json('time_slots')->nullable(); // Specific time slots (for time-based incentives)
            $table->boolean('is_active')->default(true);
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('driver_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['driver_id', 'status']);
            $table->index(['status', 'start_time', 'end_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_incentives');
    }
};
