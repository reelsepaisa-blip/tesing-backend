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
        Schema::create('driver_document_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('document_list_id')->constrained('document_lists')->onDelete('cascade');
            $table->dateTime('notified_at');
            $table->dateTime('deadline_at');
            $table->boolean('is_uploaded')->default(false);
            $table->dateTime('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['driver_id', 'document_list_id']);
            $table->index(['deadline_at', 'is_uploaded']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_document_notifications');
    }
};
