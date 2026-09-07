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
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('category')->nullable()->change();
            $table->string('subject')->nullable()->change();
        });

        Schema::table('support_messages', function (Blueprint $table) {
            $table->text('message')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('category')->nullable(false)->change();
            $table->string('subject')->nullable(false)->change();
        });

        Schema::table('support_messages', function (Blueprint $table) {
            $table->text('message')->nullable(false)->change();
        });
    }
};
