<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description')->nullable();

            // Discount configuration
            $table->string('type'); // fixed, percentage, cashback
            $table->decimal('value', 10, 2); // amount or percentage
            $table->decimal('min_order_amount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();

            // Usage limits
            $table->integer('max_uses_per_user')->nullable();
            $table->integer('max_uses_total')->nullable();

            // Validity period
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Status
            $table->string('status')->default('active'); // active, inactive, expired

            // Special flags
            $table->boolean('is_first_ride_only')->default(false);
            $table->boolean('is_referral_code')->default(false);
            $table->foreignId('referral_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Restrictions
            $table->json('city_ids')->nullable();
            $table->json('ride_type_ids')->nullable();

            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('is_referral_code');
            $table->index('referral_user_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE promo_codes ADD INDEX promo_codes_status_starts_at_expires_at_index (status(50), starts_at, expires_at)');
        } else {
            Schema::table('promo_codes', function (Blueprint $table) {
                $table->index(['status', 'starts_at', 'expires_at'], 'promo_codes_status_starts_at_expires_at_index');
            });
        }

        Schema::create('promo_code_cities', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->primary(['promo_code_id', 'city_id']);
        });

        Schema::create('promo_code_ride_types', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ride_type_id')->constrained()->cascadeOnDelete();
            $table->primary(['promo_code_id', 'ride_type_id']);
        });

        Schema::create('promo_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            $table->decimal('original_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2);
            $table->decimal('final_amount', 10, 2);

            $table->json('meta_data')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['promo_code_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['booking_id']); // One promo per booking
        });

        Schema::create('referral_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();

            $table->string('type'); // referrer_bonus, referred_bonus
            $table->decimal('amount', 10, 2);

            $table->string('status')->default('pending');
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('cancelled_reason')->nullable();

            $table->json('meta_data')->nullable();
            $table->timestamps();

            // Indexes
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE referral_bonuses ADD INDEX referral_bonuses_referrer_id_status_index (referrer_id, status(50))');
            DB::statement('ALTER TABLE referral_bonuses ADD INDEX referral_bonuses_referred_id_status_index (referred_id, status(50))');
            DB::statement('ALTER TABLE referral_bonuses ADD INDEX referral_bonuses_status_expires_at_index (status(50), expires_at)');
        } else {
            Schema::table('referral_bonuses', function (Blueprint $table) {
                $table->index(['referrer_id', 'status'], 'referral_bonuses_referrer_id_status_index');
                $table->index(['referred_id', 'status'], 'referral_bonuses_referred_id_status_index');
                $table->index(['status', 'expires_at'], 'referral_bonuses_status_expires_at_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_bonuses');
        Schema::dropIfExists('promo_usages');
        Schema::dropIfExists('promo_code_ride_types');
        Schema::dropIfExists('promo_code_cities');
        Schema::dropIfExists('promo_codes');
    }
};
