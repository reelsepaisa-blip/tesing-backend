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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('currency', 3)->default('INR')->after('amount');
            $table->string('gateway_transaction_id')->nullable()->after('payment_method');
            $table->json('gateway_response')->nullable()->after('gateway_transaction_id');
            $table->timestamp('processed_at')->nullable()->after('gateway_response');
            $table->timestamp('failed_at')->nullable()->after('processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'currency',
                'gateway_transaction_id',
                'gateway_response',
                'processed_at',
                'failed_at'
            ]);
        });
    }
};
