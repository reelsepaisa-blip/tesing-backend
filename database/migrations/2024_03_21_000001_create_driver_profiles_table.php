<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('city_id')->nullable();
            $table->text('address')->nullable();
            $table->string('license_number')->nullable();
            $table->date('license_expiry')->nullable();
            $table->string('identity_number')->nullable();
            $table->string('identity_type')->nullable(); // aadhar, pan, voter_id
            $table->date('identity_expiry')->nullable();

            // Bank Details
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('account_holder_name')->nullable();

            // Stats
            $table->decimal('commission_rate', 5, 2)->default(20.00); // percentage
            $table->integer('total_trips')->default(0);
            $table->integer('completed_trips')->default(0);
            $table->integer('cancelled_trips')->default(0);
            $table->decimal('total_earnings', 10, 2)->default(0);
            $table->decimal('total_commission', 10, 2)->default(0);
            $table->decimal('rating', 3, 1)->default(0);

            // Verification
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamp('bank_verified_at')->nullable();
            $table->timestamp('address_verified_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['driver_id', 'city_id']);
            $table->index('license_number');
            $table->index('identity_number');
            $table->index('bank_account_number');
        });
    }

    public function down()
    {
        Schema::dropIfExists('driver_profiles');
    }
};
