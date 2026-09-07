<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop existing foreign key if it exists in MySQL
        if (DB::getDriverName() === 'mysql') {
            $constraint = DB::selectOne("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'transactions'
                  AND COLUMN_NAME = 'wallet_id'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                LIMIT 1
            ");
            if ($constraint && isset($constraint->CONSTRAINT_NAME)) {
                DB::statement("ALTER TABLE `transactions` DROP FOREIGN KEY `{$constraint->CONSTRAINT_NAME}`");
            }
        }

        Schema::table('transactions', function (Blueprint $table) {
            // FIX: Must be unsignedBigInteger to match wallets.id data type
            $table->unsignedBigInteger('wallet_id')->nullable()->change();
            $table->foreign('wallet_id')->references('id')->on('wallets')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['wallet_id']);
            $table->unsignedBigInteger('wallet_id')->nullable(false)->change();
            $table->foreign('wallet_id')->references('id')->on('wallets');
        });
    }
};
