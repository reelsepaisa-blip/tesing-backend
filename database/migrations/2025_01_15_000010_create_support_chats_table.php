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
        Schema::create('support_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->onDelete('cascade');
            $table->foreignId('admin_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('sender_type', ['user', 'admin']);
            $table->text('message');
            $table->enum('message_type', ['text', 'image', 'file', 'system'])->default('text');
            $table->json('metadata')->nullable(); // For storing additional data like file URLs, attachments
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->enum('status', ['open', 'closed', 'pending'])->default('open');
            $table->string('subject')->nullable(); // Support ticket subject
            $table->string('priority')->default('medium'); // low, medium, high, urgent
            $table->timestamps();

            // Indexes for better performance
            $table->index(['user_id', 'created_at']);
            $table->index(['booking_id', 'created_at']);
            $table->index(['admin_id', 'created_at']);
            $table->index('is_read');
        });
        
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE support_chats ADD INDEX support_chats_status_priority_index (status, priority(50))');
        } else {
            Schema::table('support_chats', function (Blueprint $table) {
                $table->index(['status', 'priority'], 'support_chats_status_priority_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_chats');
    }
};
