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
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('passenger_name')->nullable()->after('user_id');
            $table->foreignId('booking_contact_id')->nullable()->after('passenger_name')->constrained('booking_contacts')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['booking_contact_id']);
            $table->dropColumn(['passenger_name', 'booking_contact_id']);
        });
    }
};
