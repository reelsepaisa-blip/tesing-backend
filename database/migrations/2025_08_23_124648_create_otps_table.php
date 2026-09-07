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
        Schema::create('otps', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15)->index();
            $table->string('otp', 6);
            $table->enum('type', ['user_login', 'driver_login', 'user_verification', 'driver_verification'])->default('user_login');
            $table->enum('status', ['pending', 'used', 'expired'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // Indexes for better performance
            $table->index(['phone', 'type', 'status']);
            $table->index(['phone', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};
