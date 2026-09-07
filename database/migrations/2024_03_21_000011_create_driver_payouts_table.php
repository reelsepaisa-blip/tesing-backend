<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            
            $table->decimal('amount', 10, 2);
            
            // Bank details (copied from driver profile for record)
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('ifsc_code');
            
            // Payout status tracking
            $table->string('status'); // pending, processing, completed, failed, cancelled
            $table->string('reference_number')->nullable(); // bank reference number
            $table->timestamp('processed_at')->nullable();
            $table->string('failed_reason')->nullable();
            $table->string('cancelled_reason')->nullable();
            
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('reference_number');
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE driver_payouts ADD INDEX driver_payouts_driver_id_status_created_at_index (driver_id, status(50), created_at)');
        } else {
            Schema::table('driver_payouts', function (Blueprint $table) {
                $table->index(['driver_id', 'status', 'created_at'], 'driver_payouts_driver_id_status_created_at_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_payouts');
    }
};








