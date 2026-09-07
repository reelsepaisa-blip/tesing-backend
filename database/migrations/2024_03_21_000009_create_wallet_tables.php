<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Wallets
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('balance', 10, 2)->default(0);
            $table->decimal('total_credit', 10, 2)->default(0);
            $table->decimal('total_debit', 10, 2)->default(0);
            $table->timestamp('last_transaction_at')->nullable();
            $table->string('status')->default('active');
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->unique('user_id');
            $table->index('last_transaction_at');
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallets ADD INDEX wallets_status_balance_index (status(50), balance)');
        } else {
            Schema::table('wallets', function (Blueprint $table) {
                $table->index(['status', 'balance'], 'wallets_status_balance_index');
            });
        }

        // Wallet Transactions
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            $table->string('type');
            $table->decimal('amount', 10, 2); // Negative for debit, positive for credit
            $table->decimal('balance', 10, 2); // Balance after transaction
            $table->string('description');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('status')->default('completed');
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['wallet_id', 'created_at']);
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallet_transactions ADD INDEX wallet_transactions_type_status_index (type(50), status(50))');
            DB::statement('ALTER TABLE wallet_transactions ADD INDEX wallet_transactions_reference_type_reference_id_index (reference_type(50), reference_id)');
        } else {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->index(['type', 'status'], 'wallet_transactions_type_status_index');
                $table->index(['reference_type', 'reference_id'], 'wallet_transactions_reference_type_reference_id_index');
            });
        }

        // Add wallet_transaction_id to bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->onDelete('set null');
        });

        // Add wallet_transaction_id to driver_profiles table for last payout reference
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreignId('last_payout_transaction_id')->nullable()->constrained('wallet_transactions')->onDelete('set null');
            $table->timestamp('last_payout_at')->nullable();
            $table->decimal('pending_payout', 10, 2)->default(0);
        });
    }

    public function down()
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropForeign(['last_payout_transaction_id']);
            $table->dropColumn(['last_payout_transaction_id', 'last_payout_at', 'pending_payout']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['wallet_transaction_id']);
            $table->dropColumn('wallet_transaction_id');
        });

        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
