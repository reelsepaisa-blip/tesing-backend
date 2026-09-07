<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Check and safely drop existing unique indexes if they exist
        if (Schema::hasTable('cities')) {
            try {
                Schema::table('cities', function (Blueprint $table) {
                    $table->dropUnique(['name', 'state', 'country']);
                });
            } catch (\Throwable $e) {
                // Ignore if index doesn't exist
            }

            try {
                Schema::table('cities', function (Blueprint $table) {
                    $table->dropUnique('cities_name_state_country_unique');
                });
            } catch (\Throwable $e) {
                // Ignore if index doesn't exist
            }

            // Safely add new unique index
            try {
                Schema::table('cities', function (Blueprint $table) {
                    $table->unique(['name', 'state', 'country', 'deleted_at'], 'cities_name_state_country_deleted_at_unique');
                });
            } catch (\Throwable $e) {
                // Ignore if already exists
            }
        }
    }

    public function down()
    {
        if (Schema::hasTable('cities')) {
            try {
                Schema::table('cities', function (Blueprint $table) {
                    $table->dropUnique('cities_name_state_country_deleted_at_unique');
                });
            } catch (\Throwable $e) {
                // Ignore
            }

            try {
                Schema::table('cities', function (Blueprint $table) {
                    $table->unique(['name', 'state', 'country']);
                });
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }
};
