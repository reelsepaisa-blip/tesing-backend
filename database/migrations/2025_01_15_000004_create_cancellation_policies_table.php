<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('allow_customer_cancellation')->default(true);
            $table->integer('free_cancellation_window')->default(60); // seconds
            $table->integer('max_cancellation_time')->default(300); // seconds
            $table->decimal('cancellation_fee', 8, 2)->default(0.00);
            $table->decimal('cancellation_fee_percentage', 5, 2)->default(0.00); // percentage
            $table->boolean('driver_gets_share')->default(false);
            $table->decimal('driver_share_percentage', 5, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
