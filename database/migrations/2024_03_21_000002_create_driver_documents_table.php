<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->string('type'); // license, identity, vehicle_rc, etc.
            $table->string('number')->nullable();
            $table->string('file_front');
            $table->string('file_back')->nullable();
            $table->date('expiry_date')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('number');
            $table->index('status');
        });
        
        if (DB::getDriverName() === 'mysql') {
            // MySQL can use a prefix index for long utf8mb4 values.
            DB::statement('ALTER TABLE driver_documents ADD INDEX driver_documents_driver_id_type_index (driver_id, type(100))');
        } else {
            Schema::table('driver_documents', function (Blueprint $table) {
                $table->index(['driver_id', 'type'], 'driver_documents_driver_id_type_index');
            });
        }
    }

    public function down()
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
            $table->dropForeign(['verified_by']);
        });
        Schema::dropIfExists('driver_documents');
    }
};








