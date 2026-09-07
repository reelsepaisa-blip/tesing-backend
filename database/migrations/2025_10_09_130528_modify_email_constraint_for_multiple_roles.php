<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing unique constraint on email
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });

        // Add a composite unique constraint on email and role_id
        // This allows the same email for different roles but prevents duplicates within the same role
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['email', 'role_id'], 'users_email_role_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the composite unique constraint
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_role_unique');
        });

        // Restore the original unique constraint on email
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
