<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('zones') || !Schema::hasColumn('zones', 'boundaries')) {
            return;
        }

        try {
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

            // If already POLYGON or GEOMETRY, keep it clean
            if (str_contains(strtolower((string) $column->COLUMN_TYPE), 'polygon')) {
                DB::statement("ALTER TABLE `zones` MODIFY `boundaries` POLYGON NULL");
                return;
            }

            // Convert invalid non-polygon data to NULL safely before changing type
            DB::statement("UPDATE `zones` SET `boundaries` = NULL WHERE `boundaries` IS NOT NULL");
            DB::statement("ALTER TABLE `zones` MODIFY `boundaries` POLYGON NULL");
        } catch (\Throwable $e) {
            // Log or ignore if table/column alter is not supported or already modified
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('zones') || !Schema::hasColumn('zones', 'boundaries')) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `zones` MODIFY `boundaries` TEXT NULL");
        } catch (\Throwable $e) {
            // Ignore error on revert
        }
    }
};
