<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cancellation_policies')->update([
            'allow_customer_cancellation' => true,
        ]);
    }

    public function down(): void
    {
        // No rollback required as we always enforce allow_customer_cancellation = true.
    }
};

