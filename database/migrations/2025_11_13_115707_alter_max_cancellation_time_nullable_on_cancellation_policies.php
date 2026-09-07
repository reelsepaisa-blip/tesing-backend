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
        Schema::table('cancellation_policies', function (Blueprint $table) {
            $table->integer('max_cancellation_time')
                ->nullable()
                ->default(null)
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cancellation_policies', function (Blueprint $table) {
            $table->integer('max_cancellation_time')
                ->nullable(false)
                ->default(300)
                ->change();
        });
    }
};
