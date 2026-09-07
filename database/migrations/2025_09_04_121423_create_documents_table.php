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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // Polymorphic relationship fields
            $table->string('documentable_type', 191); // Reduced to 191 to avoid MyISAM key length limit with utf8mb4
            $table->unsignedBigInteger('documentable_id');

            // Document details
            $table->string('type')->nullable(); // license, identity, vehicle_rc, insurance, etc.
            $table->string('number')->nullable(); // Document number
            $table->string('file_front')->nullable(); // Front side file path
            $table->string('file_back')->nullable(); // Back side file path
            $table->date('expiry_date')->nullable(); // Document expiry date

            // Status and verification
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
 
            // Additional metadata
            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('verified_by');
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE documents ADD INDEX documents_documentable_type_documentable_id_index (documentable_type(50), documentable_id)');
            DB::statement('ALTER TABLE documents ADD INDEX documents_type_status_index (type(50), status)');
        } else {
            Schema::table('documents', function (Blueprint $table) {
                $table->index(['documentable_type', 'documentable_id'], 'documents_documentable_type_documentable_id_index');
                $table->index(['type', 'status'], 'documents_type_status_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
