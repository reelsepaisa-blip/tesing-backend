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
        Schema::table('promo_codes', function (Blueprint $table) {
            // Drop the existing unique constraint on code
            $table->dropUnique(['code']);

            // Add a new unique constraint that considers only non-deleted records
            // This creates a unique index on (code, deleted_at) where deleted_at is NULL
            $table->unique(['code', 'deleted_at'], 'promo_codes_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            // Drop the composite unique constraint
            $table->dropUnique('promo_codes_code_unique');

            // Restore the original simple unique constraint
            $table->unique('code');
        });
    }
};
