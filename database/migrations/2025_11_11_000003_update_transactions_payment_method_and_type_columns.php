<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `transactions` MODIFY `type` VARCHAR(50) NOT NULL");
            DB::statement("ALTER TABLE `transactions` MODIFY `payment_method` VARCHAR(50) NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `transactions` MODIFY `type` ENUM('credit','debit','hold','release','refund') NOT NULL");
            DB::statement("ALTER TABLE `transactions` MODIFY `payment_method` ENUM('cash','wallet','card','upi') NOT NULL");
        }
    }
};
