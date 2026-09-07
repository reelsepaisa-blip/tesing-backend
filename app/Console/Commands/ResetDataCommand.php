<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use App\Services\FirebaseService;
use Illuminate\Support\Facades\DB;


class ResetDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset data - same functionality as Reset Data button';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        $this->info("=" . str_repeat("=", 60));
        $this->info("Reset Data Command Started at: " . $startTime->format('Y-m-d H:i:s'));
        $this->info("=" . str_repeat("=", 60));


        try {
            // Disable foreign key checks temporarily
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // Helper function to safely truncate a table
            $truncateTable = function ($tableName) {
                try {
                    if (DB::getSchemaBuilder()->hasTable($tableName)) {
                        DB::table($tableName)->truncate();
                        $this->info("✓ Truncated: {$tableName}");
                    }
                } catch (Exception $e) {
                    $this->warn("✗ Failed to truncate: {$tableName}");
                }
            };

            // Helper function to safely delete from a table
            $deleteFromTable = function ($tableName, $callback) {
                try {
                    if (DB::getSchemaBuilder()->hasTable($tableName)) {
                        $query = DB::table($tableName);
                        $callback($query);
                        $count = $query->count();
                        $query->delete();
                        $this->info("✓ Deleted {$count} records from: {$tableName}");
                    }
                } catch (Exception $e) {
                    $this->warn("✗ Failed to delete from: {$tableName}");
                }
            };

            // Delete users from Firebase first, then from database
            $this->info('Deleting users from Firebase and database...');
            $this->deleteUsersFromFirebaseAndDatabase();

            // Truncate bookings table
            $this->info('Truncating bookings...');
            $truncateTable('bookings');

            // Truncate booking_contacts table
            $truncateTable('booking_contacts');

            // Truncate chats table
            $truncateTable('chats');

            // Truncate commissions table
            $truncateTable('commissions');

            // Delete documents except for users 1,2,3,4,5,6,7,1214
            $this->info('Deleting documents...');
            if (DB::getSchemaBuilder()->hasTable('documents')) {
                // Delete user documents for users not in [1,2,3,4,5,6,7,1214]
                DB::table('documents')
                    ->where('documentable_type', 'App\Models\User')
                    ->whereNotIn('documentable_id', [1, 2, 3, 4, 5, 6, 7, 1214])
                    ->delete();

                // Delete vehicle documents where vehicle's driver_id is not in [1,2,3,4,5,6,7,1214]
                if (DB::getSchemaBuilder()->hasTable('vehicles')) {
                    $vehicleIdsToKeep = DB::table('vehicles')
                        ->whereIn('driver_id', [1, 2, 3, 4, 5, 6, 7, 1214])
                        ->pluck('id')
                        ->toArray();

                    if (!empty($vehicleIdsToKeep)) {
                        DB::table('documents')
                            ->where('documentable_type', 'App\Models\Vehicle')
                            ->whereNotIn('documentable_id', $vehicleIdsToKeep)
                            ->delete();
                    } else {
                        // If no vehicles to keep, delete all vehicle documents
                        DB::table('documents')
                            ->where('documentable_type', 'App\Models\Vehicle')
                            ->delete();
                    }
                }
            }

            // Truncate various tables
            $this->info('Truncating driver-related tables...');
            $truncateTable('driver_attendance');
            $truncateTable('driver_document_notifications');
            $truncateTable('driver_incentive_progress');
            $truncateTable('driver_locations');
            $truncateTable('driver_withdrawal_requests');
            $truncateTable('emergency_contacts');
            $truncateTable('issue_reports');
            $truncateTable('notifications');
            $truncateTable('otps');

            // Delete promo_codes except id 1 and 2
            $this->info('Deleting promo codes...');
            $deleteFromTable('promo_codes', function ($query) {
                $query->whereNotIn('id', [1, 2]);
            });

            // Delete promo_code_cities where promo_code_id is not 1 or 2
            $deleteFromTable('promo_code_cities', function ($query) {
                $query->whereNotIn('promo_code_id', [1, 2]);
            });

            // Truncate promo_usages table
            $truncateTable('promo_usages');

            // Truncate referral_bonuses table
            $truncateTable('referral_bonuses');

            // Truncate refund_requests table
            $truncateTable('refund_requests');

            // Delete ride_types except id 1,2,3,4,5,6,7
            $this->info('Deleting ride types...');
            $deleteFromTable('ride_types', function ($query) {
                $query->whereNotIn('id', [1, 2, 3, 4, 5, 6, 7]);
            });

            // Truncate saved_locations table
            $truncateTable('saved_locations');

            // Truncate support_activities table
            $truncateTable('support_activities');

            // Truncate support_attachments table
            $truncateTable('support_attachments');

            // Truncate support_chats table
            $truncateTable('support_chats');

            // Truncate support_messages table
            $truncateTable('support_messages');

            // Truncate support_tickets table
            $truncateTable('support_tickets');

            // Truncate transactions table
            $truncateTable('transactions');

            // Truncate upi_accounts table
            $truncateTable('upi_accounts');

            // Truncate user_debts table
            $truncateTable('user_debts');

            // Delete wallets except user_id 1,2,3,4,5,6,7,1214
            $this->info('Deleting wallets...');
            $deleteFromTable('wallets', function ($query) {
                $query->whereNotIn('user_id', [1, 2, 3, 4, 5, 6, 7, 1214]);
            });

            // Truncate wallet_transactions table
            $truncateTable('wallet_transactions');

            // Delete zones except id 1
            $this->info('Deleting zones...');
            $deleteFromTable('zones', function ($query) {
                $query->whereNotIn('id', [1]);
            });

            // Truncate zone_surge_slots table
            $truncateTable('zone_surge_slots');

            // Hard delete cities except id 1 (including soft deleted)
            $this->info('Deleting cities...');
            if (DB::getSchemaBuilder()->hasTable('cities')) {
                try {
                    DB::table('cities')
                        ->whereNotIn('id', [1])
                        ->delete();
                    $this->info('✓ Deleted cities except id 1');
                } catch (Exception $e) {
                    $this->warn('✗ Failed to delete cities');
                }
            }

            // Delete cancellation_policies except id 1,2,3,4,5,6,7
            $this->info('Deleting cancellation policies...');
            if (DB::getSchemaBuilder()->hasTable('cancellation_policies')) {
                try {
                    DB::table('cancellation_policies')
                        ->whereNotIn('id', [1, 2, 3, 4, 5, 6, 7])
                        ->delete();
                    $this->info('✓ Deleted cancellation policies except id 1-7');
                } catch (Exception $e) {
                    $this->warn('✗ Failed to delete cancellation policies');
                }
            }

            // Update driver_search_settings to default values (5, 10, 15)
            $this->info('Updating driver search settings...');
            if (DB::getSchemaBuilder()->hasTable('driver_search_settings')) {
                try {
                    DB::table('driver_search_settings')
                        ->update([
                            'round1_radius_km' => 5,
                            'round2_radius_km' => 10,
                            'round3_radius_km' => 15,
                        ]);
                    $this->info('✓ Updated driver search settings to 5, 10, 15 km');
                } catch (Exception $e) {
                    $this->warn('✗ Failed to update driver search settings');
                }
            }

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $endTime = now();
            $duration = $startTime->diffInSeconds($endTime);

            $this->info('');
            $this->info('=' . str_repeat("=", 60));
            $this->info('✅ Data reset successfully completed!');
            $this->info('Completed at: ' . $endTime->format('Y-m-d H:i:s'));
            $this->info('Duration: ' . $duration . ' seconds');
            $this->info('=' . str_repeat("=", 60));


            return Command::SUCCESS;
        } catch (Exception $e) {
            // Re-enable foreign key checks even on error
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } catch (Exception $fkException) {
                // Ignore if we can't re-enable
            }

            $endTime = now();
            $duration = $startTime->diffInSeconds($endTime);

            $this->error('');
            $this->error('=' . str_repeat("=", 60));
            $this->error('❌ Error resetting data!');
            $this->error('Error: ' . $e->getMessage());
            $this->error('Failed at: ' . $endTime->format('Y-m-d H:i:s'));
            $this->error('Duration: ' . $duration . ' seconds');
            $this->error('=' . str_repeat("=", 60));


            return Command::FAILURE;
        }
    }

    /**
     * Delete users from Firebase first, then from database
     */
    private function deleteUsersFromFirebaseAndDatabase()
    {
        try {
            // Get all users that will be deleted (except id 1,2,3,4,5,6,7,1214)
            $usersToDelete = DB::table('users')
                ->whereNotIn('id', [1, 2, 3, 4, 5, 6, 7, 1214])
                ->whereNotNull('firebase_uid')
                ->select('id', 'firebase_uid', 'email', 'name')
                ->get();

            if ($usersToDelete->count() > 0) {
                $this->info("Found {$usersToDelete->count()} users with Firebase UID to delete from Firebase");

                $firebaseService = app(FirebaseService::class);
                $deletedCount = 0;
                $failedCount = 0;

                foreach ($usersToDelete as $user) {
                    try {
                        if (!empty($user->firebase_uid)) {
                            $deleted = $firebaseService->deleteUserByUid($user->firebase_uid);
                            if ($deleted) {
                                $deletedCount++;
                                $this->info("✓ Deleted Firebase user: {$user->name} (UID: {$user->firebase_uid})");
                            } else {
                                $failedCount++;
                                $this->warn("✗ Failed to delete Firebase user: {$user->name} (UID: {$user->firebase_uid})");
                            }
                        }
                    } catch (Exception $e) {
                        $failedCount++;
                        $this->warn("✗ Exception deleting Firebase user {$user->name}: " . $e->getMessage());
                    }
                }

                $this->info("Firebase deletion summary: {$deletedCount} deleted, {$failedCount} failed out of {$usersToDelete->count()} total");

            } else {
                $this->info('No users with Firebase UID found to delete');
            }

            // Now delete users from database
            $dbDeletedCount = DB::table('users')
                ->whereNotIn('id', [1, 2, 3, 4, 5, 6, 7, 1214])
                ->delete();

            $this->info("✓ Deleted {$dbDeletedCount} users from database");

            
        } catch (Exception $e) {
            $this->error('Error deleting users from Firebase and database: ' . $e->getMessage());
            // Continue with database deletion even if Firebase deletion fails
            try {
                $dbDeletedCount = DB::table('users')
                    ->whereNotIn('id', [1, 2, 3, 4, 5, 6, 7, 1214])
                    ->delete();
                $this->info("✓ Deleted {$dbDeletedCount} users from database (Firebase deletion failed)");
            } catch (Exception $dbException) {
                $this->error('Failed to delete users from database: ' . $dbException->getMessage());
            }
        }
    }
}
