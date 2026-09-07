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
        Schema::create('driver_incentive_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('incentive_id');
            $table->json('current_progress'); // Current progress data
            $table->json('milestone_progress')->nullable(); // Progress for each milestone
            $table->decimal('total_earned', 10, 2)->default(0);
            $table->boolean('is_completed')->default(false);
            $table->datetime('completed_at')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->foreign('driver_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('incentive_id')->references('id')->on('driver_incentives')->onDelete('cascade');
            $table->unique(['driver_id', 'incentive_id']);
            $table->index(['driver_id', 'is_completed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_incentive_progress');
    }
};

