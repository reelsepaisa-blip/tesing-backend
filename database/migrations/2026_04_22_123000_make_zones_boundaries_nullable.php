<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('zones') || !Schema::hasColumn('zones', 'boundaries')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $column = DB::selectOne("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'zones'
              AND COLUMN_NAME = 'boundaries'
            LIMIT 1
        ");

        if (!$column || empty($column->COLUMN_TYPE)) {
            return;
        }

        DB::statement("ALTER TABLE zones MODIFY boundaries {$column->COLUMN_TYPE} NULL");
    }

    public function down(): void
    {
        // Keep nullable on rollback to avoid data-loss regressions.
    }
};
