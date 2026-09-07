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
        Schema::table('users', function (Blueprint $table) {
            // Drop the existing unique constraint on phone
            $table->dropUnique(['phone']);

            // Add a composite unique constraint for phone + role_id
            // This allows the same phone number to be used for different roles
            $table->unique(['phone', 'role_id'], 'users_phone_role_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the composite unique constraint
            $table->dropUnique('users_phone_role_unique');

            // Restore the original unique constraint on phone
            $table->unique(['phone']);
        });
    }
};
