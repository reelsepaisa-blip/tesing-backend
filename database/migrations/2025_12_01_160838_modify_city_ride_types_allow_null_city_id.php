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
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('city_ride_types') || !Schema::hasColumn('city_ride_types', 'city_id')) {
            return;
        }

        $fkName = $this->getForeignKeyName('city_ride_types', 'city_id');
        if ($fkName) {
            DB::statement("ALTER TABLE city_ride_types DROP FOREIGN KEY `{$fkName}`");
        }

        if ($this->indexExists('city_ride_types', 'city_ride_types_city_id_ride_type_id_unique')) {
            DB::statement('ALTER TABLE city_ride_types DROP INDEX city_ride_types_city_id_ride_type_id_unique');
        }

        // Make city_id nullable
        DB::statement('ALTER TABLE city_ride_types MODIFY city_id BIGINT UNSIGNED NULL');

        if (!$this->getForeignKeyName('city_ride_types', 'city_id')) {
            DB::statement('ALTER TABLE city_ride_types ADD CONSTRAINT city_ride_types_city_id_foreign FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE');
        }

        if (!$this->indexExists('city_ride_types', 'city_ride_types_ride_type_city_unique')) {
            DB::statement('ALTER TABLE city_ride_types ADD UNIQUE KEY city_ride_types_ride_type_city_unique (ride_type_id, city_id)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('city_ride_types') || !Schema::hasColumn('city_ride_types', 'city_id')) {
            return;
        }

        if ($this->indexExists('city_ride_types', 'city_ride_types_ride_type_city_unique')) {
            DB::statement('ALTER TABLE city_ride_types DROP INDEX city_ride_types_ride_type_city_unique');
        }

        $fkName = $this->getForeignKeyName('city_ride_types', 'city_id');
        if ($fkName) {
            DB::statement("ALTER TABLE city_ride_types DROP FOREIGN KEY `{$fkName}`");
        }

        // Make city_id not nullable again
        DB::statement('ALTER TABLE city_ride_types MODIFY city_id BIGINT UNSIGNED NOT NULL');

        if (!$this->getForeignKeyName('city_ride_types', 'city_id')) {
            DB::statement('ALTER TABLE city_ride_types ADD CONSTRAINT city_ride_types_city_id_foreign FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE');
        }

        if (!$this->indexExists('city_ride_types', 'city_ride_types_city_id_ride_type_id_unique')) {
            DB::statement('ALTER TABLE city_ride_types ADD UNIQUE KEY city_ride_types_city_id_ride_type_id_unique (city_id, ride_type_id)');
        }
    }

    private function getForeignKeyName(string $table, string $column): ?string
    {
        $row = DB::selectOne("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1
        ", [$table, $column]);

        return $row->CONSTRAINT_NAME ?? null;
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne("
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            LIMIT 1
        ", [$table, $index]);

        return !empty($row?->INDEX_NAME);
    }
};
