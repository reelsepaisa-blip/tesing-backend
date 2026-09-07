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
            // Drop columns if they already exist
            if (Schema::hasColumn('users', 'select_latitude')) {
                $table->dropColumn('select_latitude');
            }
            if (Schema::hasColumn('users', 'select_longitude')) {
                $table->dropColumn('select_longitude');
            }
        });

        // Add the columns
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('select_latitude', 10, 8)->nullable()->after('last_longitude');
            $table->decimal('select_longitude', 11, 8)->nullable()->after('select_latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['select_latitude', 'select_longitude']);
        });
    }
};
