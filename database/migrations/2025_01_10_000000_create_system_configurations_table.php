<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('category')->index(); // payment, notification, maps, etc.
            $table->string('key')->unique(); // razorpay_key_id, fcm_server_key, etc.
            $table->text('value')->nullable(); // encrypted value
            $table->string('description')->nullable();
            $table->boolean('is_encrypted')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE system_configurations ADD INDEX system_configurations_category_is_active_index (category(50), is_active)');
        } else {
            Schema::table('system_configurations', function (Blueprint $table) {
                $table->index(['category', 'is_active'], 'system_configurations_category_is_active_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_configurations');
    }
};
