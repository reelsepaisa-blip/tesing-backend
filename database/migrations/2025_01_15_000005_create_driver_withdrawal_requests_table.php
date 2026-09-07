<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('driver_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');

            // Fixed: Foreign key constraint ko abhi ke liye loose rakha hai 
            // taaki agar bank_accounts table baad mein bhi bane toh error na aaye
            $table->unsignedBigInteger('bank_account_id')->nullable();

            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('rejection_reason')->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index(['driver_id', 'status']);
        });

        // Safe Foreign Key creation (adds relation only if bank_accounts table exists)
        if (Schema::hasTable('bank_accounts')) {
            Schema::table('driver_withdrawal_requests', function (Blueprint $table) {
                $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('driver_withdrawal_requests');
    }
};
