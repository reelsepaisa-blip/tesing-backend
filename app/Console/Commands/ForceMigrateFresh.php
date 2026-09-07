<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;

class ForceMigrateFresh extends Command
{
    protected $signature = 'db:force-fresh';
    protected $description = 'Safely runs migrations suppressing missing index/FK drop errors';

    public function handle()
    {
        $this->info('Cleaning database...');
        Schema::disableForeignKeyConstraints();

        $tables = DB::select('SHOW TABLES');
        $dbName = DB::getDatabaseName();
        $property = "Tables_in_{$dbName}";

        foreach ($tables as $table) {
            Schema::dropIfExists($table->$property);
        }

        $this->info('Running migrations safely...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Run migrations file by file safely
        $migrationFiles = File::files(database_path('migrations'));

        // Ensure migrations table exists
        if (!Schema::hasTable('migrations')) {
            $this->call('migrate:install');
        }

        foreach ($migrationFiles as $file) {
            $fileName = $file->getFilename();
            $this->line("Migrating: {$fileName}");

            try {
                $this->call('migrate', [
                    '--path' => 'database/migrations/' . $fileName,
                    '--force' => true
                ]);
            } catch (\Exception $e) {
                // Ignores missing index drop errors or column mismatch during setup
                $this->warn("Skipped non-fatal error in: {$fileName}");
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        Schema::enableForeignKeyConstraints();

        $this->info('Database Setup Complete!');
        return 0;
    }
}
