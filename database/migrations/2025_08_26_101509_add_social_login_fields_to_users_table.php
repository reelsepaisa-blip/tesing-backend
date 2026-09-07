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
            // Social login fields
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('apple_id')->nullable()->unique()->after('google_id');

            // Password reset fields
            $table->string('password_reset_token')->nullable()->after('password');
            $table->timestamp('password_reset_expires_at')->nullable()->after('password_reset_token');

            // Make email nullable for social login users who might not provide email
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'apple_id', 'password_reset_token', 'password_reset_expires_at']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
