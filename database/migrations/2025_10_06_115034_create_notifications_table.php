<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type'); // document_status, booking_update, driver_verified, etc.
            $table->string('title');
            $table->text('body');
            $table->string('icon')->nullable();
            $table->string('sound')->nullable();
            $table->json('data')->nullable(); // Additional data payload
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->string('fcm_message_id')->nullable(); // FCM message ID for tracking
            $table->string('status')->default('pending'); // pending, sent, failed, delivered
            $table->text('error_message')->nullable(); // Error message if failed
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Indexes for performance
            $table->index(['user_id', 'is_read']);
            $table->index('created_at');
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE notifications ADD INDEX notifications_type_status_index (type(50), status(50))');
        } else {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index(['type', 'status'], 'notifications_type_status_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
