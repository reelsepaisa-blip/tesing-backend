<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Support Tickets
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('booking_id')->nullable()->constrained()->onDelete('set null');
            $table->string('category');
            $table->string('subject');
            $table->string('priority')->default('medium');
            $table->string('status')->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('ticket_number');
            $table->index('assigned_to');
            $table->index('last_reply_at');
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE support_tickets ADD INDEX support_tickets_status_priority_index (status(50), priority(50))');
            DB::statement('ALTER TABLE support_tickets ADD INDEX support_tickets_category_status_index (category(50), status(50))');
        } else {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->index(['status', 'priority'], 'support_tickets_status_priority_index');
                $table->index(['category', 'status'], 'support_tickets_category_status_index');
            });
        }

        // Support Messages
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('message');
            $table->boolean('is_internal')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['ticket_id', 'created_at']);
            $table->index(['user_id', 'is_internal']);
            $table->index('read_at');
        });

        // Support Attachments
        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->onDelete('cascade');
            $table->foreignId('message_id')->nullable()->constrained('support_messages')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('file_name');
            $table->string('file_path');
            $table->integer('file_size');
            $table->string('file_type');
            $table->boolean('is_internal')->default(false);
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['ticket_id', 'message_id']);
            $table->index(['user_id', 'is_internal']);
            $table->index('file_type');
        });

        // Support Activities
        Schema::create('support_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type');
            $table->string('description');
            $table->json('meta_data')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['ticket_id', 'created_at']);
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE support_activities ADD INDEX support_activities_user_id_type_index (user_id, type(50))');
        } else {
            Schema::table('support_activities', function (Blueprint $table) {
                $table->index(['user_id', 'type'], 'support_activities_user_id_type_index');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('support_activities');
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};








