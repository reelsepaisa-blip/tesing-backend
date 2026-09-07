<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('driver_incentives') || !Schema::hasColumn('driver_incentives', 'driver_id')) {
            return;
        }

        $foreignKeyName = null;
        if (DB::getDriverName() === 'mysql') {
            $constraint = DB::selectOne("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'driver_incentives'
                  AND COLUMN_NAME = 'driver_id'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                LIMIT 1
            ");
            $foreignKeyName = $constraint->CONSTRAINT_NAME ?? null;
        }

        if ($foreignKeyName) {
            DB::statement("ALTER TABLE `driver_incentives` DROP FOREIGN KEY `{$foreignKeyName}`");
        }

        Schema::table('driver_incentives', function (Blueprint $table) {
            $table->unsignedBigInteger('driver_id')->nullable()->change();
        });

        if ($foreignKeyName) {
            Schema::table('driver_incentives', function (Blueprint $table) {
                $table->foreign('driver_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('driver_incentives') || !Schema::hasColumn('driver_incentives', 'driver_id')) {
            return;
        }

        $foreignKeyName = null;
        if (DB::getDriverName() === 'mysql') {
            $constraint = DB::selectOne("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'driver_incentives'
                  AND COLUMN_NAME = 'driver_id'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                LIMIT 1
            ");
            $foreignKeyName = $constraint->CONSTRAINT_NAME ?? null;
        }

        if ($foreignKeyName) {
            DB::statement("ALTER TABLE `driver_incentives` DROP FOREIGN KEY `{$foreignKeyName}`");
        }

        Schema::table('driver_incentives', function (Blueprint $table) {
            $table->unsignedBigInteger('driver_id')->nullable(false)->change();
        });

        if ($foreignKeyName) {
            Schema::table('driver_incentives', function (Blueprint $table) {
                $table->foreign('driver_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }
};
