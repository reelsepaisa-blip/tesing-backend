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
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->string('vehicle_registration_number')->nullable()->after('date_of_birth');
            $table->string('vehicle_make')->nullable()->after('vehicle_registration_number');
            $table->string('vehicle_model')->nullable()->after('vehicle_make');
            $table->year('vehicle_year')->nullable()->after('vehicle_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth',
                'vehicle_registration_number',
                'vehicle_make',
                'vehicle_model',
                'vehicle_year'
            ]);
        });
    }
};
