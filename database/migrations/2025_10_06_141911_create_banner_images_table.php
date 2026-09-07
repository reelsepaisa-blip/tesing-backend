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
        Schema::create('banner_images', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path');
            $table->string('image_url')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->enum('row_position', ['first', 'second'])->default('first');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('link_url')->nullable();
            $table->string('link_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['row_position', 'sort_order']);
            $table->index(['is_active', 'row_position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banner_images');
    }
};
