<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Help Categories
        Schema::create('help_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('help_categories')->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('meta_keywords')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['parent_id', 'order']);
            $table->index('slug');
        });

        // Help Tags
        Schema::create('help_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('slug');
        });

        // Help Articles
        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('help_categories')->onDelete('cascade');
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->text('excerpt')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->integer('order')->default(0);
            $table->integer('view_count')->default(0);
            $table->integer('helpful_count')->default(0);
            $table->integer('not_helpful_count')->default(0);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('meta_keywords')->nullable();
            $table->json('related_articles')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['category_id', 'is_published', 'published_at']);
            $table->index('slug');
            $table->index(['is_featured', 'order']);
            $table->index('view_count');
        });

        // Help Article Tags (Pivot)
        Schema::create('help_article_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('help_articles')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained('help_tags')->onDelete('cascade');
            $table->timestamps();

            // Indexes
            $table->unique(['article_id', 'tag_id']);
        });

        // Help Article Attachments
        Schema::create('help_article_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('help_articles')->onDelete('cascade');
            $table->string('name');
            $table->string('file_name');
            $table->string('file_path');
            $table->integer('file_size');
            $table->string('file_type');
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['article_id', 'order']);
        });

        // Help Article Feedback
        Schema::create('help_article_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('help_articles')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->boolean('is_helpful');
            $table->text('comment')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['article_id', 'is_helpful']);
            $table->index('user_id');
            $table->index('ip_address');
        });
    }

    public function down()
    {
        Schema::dropIfExists('help_article_feedback');
        Schema::dropIfExists('help_article_attachments');
        Schema::dropIfExists('help_article_tags');
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('help_tags');
        Schema::dropIfExists('help_categories');
    }
};








