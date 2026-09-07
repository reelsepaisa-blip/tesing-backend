<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicles', 'step_2')) {
                $table->tinyInteger('step_2')->default(0)->after('status');
            }

            if (!Schema::hasColumn('vehicles', 'step_3')) {
                $table->tinyInteger('step_3')->default(0)->after('step_2');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $dropColumns = [];

            if (Schema::hasColumn('vehicles', 'step_2')) {
                $dropColumns[] = 'step_2';
            }

            if (Schema::hasColumn('vehicles', 'step_3')) {
                $dropColumns[] = 'step_3';
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
