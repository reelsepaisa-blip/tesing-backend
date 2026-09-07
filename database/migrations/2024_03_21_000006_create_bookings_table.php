<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('driver_id')->nullable()->constrained('users');
            $table->foreignId('ride_type_id')->constrained();
            $table->foreignId('pickup_zone_id')->constrained('zones');
            $table->foreignId('dropoff_zone_id')->constrained('zones');

            // Fixed: Changed text to string(500) so MySQL can build indexes on them properly
            $table->string('pickup_location', 500);
            $table->string('dropoff_location', 500);
            $table->text('pickup_address');
            $table->text('dropoff_address');

            // Status
            $table->enum('status', [
                'pending',
                'searching',
                'accepted',
                'arrived',
                'started',
                'completed',
                'cancelled',
                'expired'
            ])->default('pending');

            // Payment
            $table->enum('payment_method', [
                'cash',
                'wallet',
                'online',
                'split'
            ])->nullable();
            $table->enum('payment_status', [
                'pending',
                'paid',
                'failed',
                'refunded'
            ])->default('pending');

            // Estimates
            $table->decimal('estimated_distance', 10, 2)->nullable(); // in km
            $table->integer('estimated_duration')->nullable(); // in minutes

            // Actuals
            $table->decimal('actual_distance', 10, 2)->nullable(); // in km
            $table->integer('actual_duration')->nullable(); // in minutes

            // Fare Breakdown
            $table->decimal('base_fare', 10, 2)->nullable();
            $table->decimal('distance_fare', 10, 2)->nullable();
            $table->decimal('time_fare', 10, 2)->nullable();
            $table->decimal('waiting_charge', 10, 2)->nullable();
            $table->decimal('cancellation_charge', 10, 2)->nullable();
            $table->decimal('night_charge', 10, 2)->nullable();

            // Surge
            $table->decimal('surge_multiplier', 4, 2)->default(1.00);
            $table->decimal('surge_amount', 10, 2)->nullable();

            // Totals
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();

            // Commission
            $table->decimal('admin_commission_rate', 5, 2)->nullable();
            $table->decimal('admin_commission', 10, 2)->nullable();
            $table->decimal('driver_amount', 10, 2)->nullable();

            // Discounts
            $table->string('promo_code')->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();

            // Split Payment
            $table->decimal('wallet_amount', 10, 2)->nullable();
            $table->decimal('online_paid_amount', 10, 2)->nullable();
            $table->decimal('cash_amount', 10, 2)->nullable();

            // Timestamps
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Cancellation
            $table->string('cancellation_reason')->nullable();
            $table->string('cancelled_by_type')->nullable();
            $table->unsignedBigInteger('cancelled_by_id')->nullable();

            // Ratings & Reviews
            $table->decimal('user_rating', 2, 1)->nullable();
            $table->text('user_review')->nullable();
            $table->decimal('driver_rating', 2, 1)->nullable();
            $table->text('driver_review')->nullable();

            // Other
            $table->integer('waiting_time')->nullable(); // in minutes
            $table->string('otp', 6);
            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('booking_code');
            $table->index(['user_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['payment_status', 'payment_method']);
            $table->index('pickup_location');
            $table->index('dropoff_location');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE bookings ADD INDEX bookings_cancelled_by_type_cancelled_by_id_index (cancelled_by_type(100), cancelled_by_id)');
        } else {
            Schema::table('bookings', function (Blueprint $table) {
                $table->index(['cancelled_by_type', 'cancelled_by_id'], 'bookings_cancelled_by_type_cancelled_by_id_index');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('bookings');
    }
};
