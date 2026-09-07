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
            $table->tinyInteger('step_0')->default(0)->after('is_register');
            $table->tinyInteger('step_1')->default(0)->after('step_0');
            $table->tinyInteger('step_2')->default(0)->after('step_1');
            $table->tinyInteger('step_3')->default(0)->after('step_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['step_0', 'step_1', 'step_2', 'step_3']);
        });
    }
};
