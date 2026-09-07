<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('reason');
            $table->text('description')->nullable();
            $table->decimal('requested_amount', 10, 2);
            $table->string('status')->default('pending'); // pending, approved, partially_approved, rejected
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->string('refund_source')->nullable(); // admin_account, driver_wallet
            $table->text('admin_notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE refund_requests ADD INDEX refund_requests_booking_id_status_index (booking_id, status(50))');
            DB::statement('ALTER TABLE refund_requests ADD INDEX refund_requests_user_id_status_index (user_id, status(50))');
        } else {
            Schema::table('refund_requests', function (Blueprint $table) {
                $table->index(['booking_id', 'status'], 'refund_requests_booking_id_status_index');
                $table->index(['user_id', 'status'], 'refund_requests_user_id_status_index');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('refund_requests');
    }
};
