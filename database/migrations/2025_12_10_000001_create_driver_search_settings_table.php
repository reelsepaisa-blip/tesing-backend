<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_search_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('round1_radius_km', 8, 2)->default(5.00);
            $table->decimal('round2_radius_km', 8, 2)->default(10.00);
            $table->decimal('round3_radius_km', 8, 2)->default(15.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_search_settings');
    }
};

