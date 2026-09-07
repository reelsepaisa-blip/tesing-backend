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
        // Add driver support to wallets table if not exists
        if (Schema::hasTable('wallets') && !Schema::hasColumn('wallets', 'driver_id')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->foreignId('driver_id')->nullable()->after('user_id')->constrained('users')->onDelete('cascade');
                $table->index('driver_id');
            });
        }

        // Add driver support to wallet_transactions table if exists
        if (Schema::hasTable('wallet_transactions') && !Schema::hasColumn('wallet_transactions', 'driver_id')) {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->foreignId('driver_id')->nullable()->after('wallet_id')->constrained('users')->onDelete('cascade');
                $table->index('driver_id');
            });
        }

        // Add driver support to transactions table if not exists
        if (Schema::hasTable('transactions') && !Schema::hasColumn('transactions', 'driver_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->foreignId('driver_id')->nullable()->after('user_id')->constrained('users')->onDelete('cascade');
                $table->index('driver_id');
            });
        }

        // Add driver earnings fields to users table
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'total_earnings_this_week')) {
                    $table->decimal('total_earnings_this_week', 10, 2)->default(0)->after('email');
                }
                if (!Schema::hasColumn('users', 'max_withdrawal_limit')) {
                    $table->decimal('max_withdrawal_limit', 10, 2)->default(100)->after('total_earnings_this_week');
                }
                if (!Schema::hasColumn('users', 'scheduled_payout_date')) {
                    $table->string('scheduled_payout_date')->nullable()->after('max_withdrawal_limit');
                }
            });
        }

        // Update issue_reports table to support transaction_id and reason_id
        if (Schema::hasTable('issue_reports')) {
            Schema::table('issue_reports', function (Blueprint $table) {
                if (!Schema::hasColumn('issue_reports', 'transaction_id')) {
                    $table->string('transaction_id')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('issue_reports', 'reason_id')) {
                    $table->unsignedBigInteger('reason_id')->nullable()->after('transaction_id');
                }
                if (!Schema::hasColumn('issue_reports', 'screenshot_path')) {
                    $table->string('screenshot_path')->nullable()->after('description');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove driver support from wallets
        if (Schema::hasTable('wallets') && Schema::hasColumn('wallets', 'driver_id')) {
            Schema::table('wallets', function (Blueprint $table) {
                $table->dropForeign(['driver_id']);
                $table->dropColumn('driver_id');
            });
        }

        // Remove driver support from wallet_transactions
        if (Schema::hasTable('wallet_transactions') && Schema::hasColumn('wallet_transactions', 'driver_id')) {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->dropForeign(['driver_id']);
                $table->dropColumn('driver_id');
            });
        }

        // Remove driver support from transactions
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'driver_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropForeign(['driver_id']);
                $table->dropColumn('driver_id');
            });
        }

        // Remove driver earnings fields from users
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'total_earnings_this_week')) {
                    $table->dropColumn('total_earnings_this_week');
                }
                if (Schema::hasColumn('users', 'max_withdrawal_limit')) {
                    $table->dropColumn('max_withdrawal_limit');
                }
                if (Schema::hasColumn('users', 'scheduled_payout_date')) {
                    $table->dropColumn('scheduled_payout_date');
                }
            });
        }

        // Remove issue_reports columns
        if (Schema::hasTable('issue_reports')) {
            Schema::table('issue_reports', function (Blueprint $table) {
                if (Schema::hasColumn('issue_reports', 'transaction_id')) {
                    $table->dropColumn('transaction_id');
                }
                if (Schema::hasColumn('issue_reports', 'reason_id')) {
                    $table->dropColumn('reason_id');
                }
                if (Schema::hasColumn('issue_reports', 'screenshot_path')) {
                    $table->dropColumn('screenshot_path');
                }
            });
        }
    }
};
