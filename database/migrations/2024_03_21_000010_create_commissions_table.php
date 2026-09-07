<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ride_type_id')->constrained()->cascadeOnDelete();
            
            $table->decimal('base_fare', 10, 2);
            $table->decimal('total_fare', 10, 2);
            
            $table->string('commission_type'); // fixed, percentage
            $table->decimal('commission_value', 10, 2); // fixed amount or percentage value
            $table->decimal('commission_amount', 10, 2); // calculated commission amount
            
            $table->decimal('driver_amount', 10, 2); // amount payable to driver
            
            $table->decimal('tax_percentage', 5, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            
            $table->json('meta_data')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['driver_id', 'created_at']);
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};








