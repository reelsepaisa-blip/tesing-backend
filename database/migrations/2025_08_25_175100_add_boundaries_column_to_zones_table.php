<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Check if column already exists before adding
        if (!Schema::hasColumn('zones', 'boundaries')) {
            Schema::table('zones', function (Blueprint $table) {
                $table->text('boundaries')->nullable()->after('description');
            });
        }
    }

    public function down()
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn('boundaries');
        });
    }
};
