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
        // Add the boundaries column as POLYGON type
        // DB::statement('ALTER TABLE zones ADD COLUMN boundaries POLYGON NULL AFTER description');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the boundaries column
        // DB::statement('ALTER TABLE zones DROP COLUMN boundaries');
    }
};
