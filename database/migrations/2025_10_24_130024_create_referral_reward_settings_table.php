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
        Schema::create('referral_reward_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Default Referral Settings');
            $table->decimal('referrer_reward', 10, 2)->default(100.00);
            $table->decimal('referred_reward', 10, 2)->default(100.00);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_reward_settings');
    }
};
