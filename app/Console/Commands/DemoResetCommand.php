<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DemoResetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:reset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset demo data (only works when DEMO_MODE is enabled)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!config('app.demo_mode', false)) {
            $this->error('Demo mode is not enabled. This command can only run when DEMO_MODE=true');
            return Command::FAILURE;
        }

        $this->info('Starting demo data reset...');
        
        try {
            Artisan::call('db:seed', [
                '--class' => 'DemoResetSeeder',
                '--force' => true,
            ]);

            $this->info('Demo data reset completed successfully!');
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('Error resetting demo data: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}














