<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Cash", "Credit Card", "PayPal", "RazorPay"
            $table->string('code')->unique(); // e.g., "cash", "credit_card", "paypal", "razorpay"
            $table->string('type'); // e.g., "cash", "card", "wallet", "online"
            $table->string('description')->nullable();
            $table->string('icon')->nullable(); // Icon class or URL
            $table->string('color')->nullable(); // Hex color code
            $table->boolean('is_active')->default(true);
            $table->boolean('is_online')->default(false); // Whether it's an online payment method
            $table->boolean('requires_verification')->default(false); // Whether it requires additional verification
            $table->decimal('min_amount', 10, 2)->nullable(); // Minimum transaction amount
            $table->decimal('max_amount', 10, 2)->nullable(); // Maximum transaction amount
            $table->decimal('processing_fee_percentage', 5, 2)->default(0); // Processing fee percentage
            $table->decimal('processing_fee_fixed', 10, 2)->default(0); // Fixed processing fee
            $table->integer('sort_order')->default(0); // For ordering in UI
            $table->json('configuration')->nullable(); // Store payment gateway specific config
            $table->json('supported_currencies')->nullable(); // Supported currencies
            $table->json('supported_countries')->nullable(); // Supported countries
            $table->string('status')->default('active'); // active, inactive, maintenance
            $table->text('status_message')->nullable(); // Message to show when inactive
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('sort_order');
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payment_methods ADD INDEX payment_methods_is_active_status_index (is_active, status(50))');
            DB::statement('ALTER TABLE payment_methods ADD INDEX payment_methods_type_is_active_index (type(50), is_active)');
        } else {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->index(['is_active', 'status'], 'payment_methods_is_active_status_index');
                $table->index(['type', 'is_active'], 'payment_methods_type_is_active_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
