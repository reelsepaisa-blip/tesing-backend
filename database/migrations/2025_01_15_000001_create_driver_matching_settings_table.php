<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('driver_matching_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('idle'); // 'idle' or 'fallback'
            $table->json('weights'); // JSON configuration for weights
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('driver_matching_settings');
    }
};
