<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('ride_type_id')->constrained()->onDelete('cascade');
            $table->string('brand');
            $table->string('model');
            $table->string('year', 4);
            $table->string('color');
            $table->string('license_plate')->unique();
            $table->string('registration_number')->unique();
            $table->date('registration_expiry');
            $table->date('insurance_expiry');
            $table->enum('status', ['pending', 'active', 'inactive', 'maintenance', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->json('features')->nullable();
            $table->json('documents')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['driver_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('license_plate');
            $table->index('registration_number');
            $table->index(['registration_expiry', 'insurance_expiry']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('vehicles');
    }
};
