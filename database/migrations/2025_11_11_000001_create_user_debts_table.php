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
        Schema::create('user_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');
            $table->foreignId('original_booking_id')
                ->nullable()
                ->constrained('bookings')
                ->nullOnDelete();
            $table->foreignId('applied_booking_id')
                ->nullable()
                ->constrained('bookings')
                ->nullOnDelete();
            $table->string('type')->default('cancellation_fee');
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_settled', 10, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('status')->default('pending');
            $table->text('description')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE user_debts ADD INDEX user_debts_user_id_status_index (user_id, status(50))');
        } else {
            Schema::table('user_debts', function (Blueprint $table) {
                $table->index(['user_id', 'status'], 'user_debts_user_id_status_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_debts');
    }
};
