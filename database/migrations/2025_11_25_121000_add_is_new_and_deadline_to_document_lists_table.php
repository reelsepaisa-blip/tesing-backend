<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lists', function (Blueprint $table) {
            $table->boolean('is_new')->default(false)->after('is_active');
            $table->unsignedInteger('upload_deadline_hours')->nullable()->after('is_new');
        });
    }

    public function down(): void
    {
        Schema::table('document_lists', function (Blueprint $table) {
            $table->dropColumn(['is_new', 'upload_deadline_hours']);
        });
    }
};























