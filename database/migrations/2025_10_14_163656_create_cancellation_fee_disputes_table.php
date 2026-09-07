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
        Schema::create('cancellation_fee_disputes', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('driver_id')->nullable()->constrained('users')->onDelete('cascade');

            // Dispute details
            $table->enum('dispute_reason', [
                'passenger_didnt_show_up',
                'incorrect_fee_charged',
                'route_blocked_traffic',
                'wrong_pickup_location',
                'navigation_app_error',
                'other'
            ]);
            $table->text('custom_reason')->nullable();
            $table->text('description')->nullable();

            // File upload
            $table->string('screenshot_path')->nullable();

            // Status and resolution
            $table->enum('status', [
                'pending',
                'under_review',
                'approved',
                'rejected',
                'resolved'
            ])->default('pending');
            $table->text('admin_response')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Additional metadata
            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['booking_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['dispute_reason', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cancellation_fee_disputes');
    }
};
