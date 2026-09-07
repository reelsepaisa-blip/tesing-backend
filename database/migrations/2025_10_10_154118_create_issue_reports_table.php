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
        Schema::create('issue_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Issue details
            $table->enum('issue_type', [
                'rider_didnt_show_up',
                'wrong_pickup',
                'rider_delayed',
                'traffic_issue',
                'navigation_problem',
                'custom'
            ]);
            $table->text('custom_issue')->nullable();
            $table->text('description')->nullable();

            // Status and priority
            $table->enum('status', [
                'reported',
                'in_progress',
                'resolved',
                'closed'
            ])->default('reported');
            $table->enum('priority', [
                'low',
                'medium',
                'high',
                'urgent'
            ])->default('medium');

            // Timestamps
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            // Additional data
            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['booking_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['issue_type', 'status']);
            $table->index('reported_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issue_reports');
    }
};
