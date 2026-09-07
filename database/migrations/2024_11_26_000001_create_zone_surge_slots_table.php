<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('zone_surge_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained()->onDelete('cascade');
            
            // Day of week: 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            $table->tinyInteger('day_of_week')->unsigned();
            
            // Time slots (time only, not datetime)
            $table->time('start_time');
            $table->time('end_time');
            
            // Surge multiplier for this slot
            $table->decimal('surge_multiplier', 4, 2)->default(1.50);
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index(['zone_id', 'day_of_week', 'is_active']);
            $table->index(['day_of_week', 'start_time', 'end_time']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('zone_surge_slots');
    }
};

