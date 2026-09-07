<?php

namespace App\Console\Commands;

use Exception;
use App\Models\ReferralBonus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;


class ExpireReferralBonuses extends Command
{
    
    protected $signature = 'referral-bonuses:expire';

    
    protected $description = 'Automatically expire referral bonuses that have passed their expiration date';

    
    public function handle()
    {
        $this->info('Checking for expired referral bonuses...');

        $expiredBonuses = ReferralBonus::where('status', ReferralBonus::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expiredBonuses->isEmpty()) {
            $this->info('No expired referral bonuses found.');
            return 0;
        }

        $this->info("Found {$expiredBonuses->count()} expired referral bonus(es).");

        $expiredCount = 0;

        foreach ($expiredBonuses as $bonus) {
            try {
                DB::transaction(function () use ($bonus, &$expiredCount) {
                    $bonus->update([
                        'status' => ReferralBonus::STATUS_EXPIRED,
                    ]);

                    $expiredCount++;

                });

                $this->line("Expired referral bonus #{$bonus->id} (Amount: ₹{$bonus->amount})");
            } catch (Exception $e) {
                $this->error("Failed to expire referral bonus #{$bonus->id}: " . $e->getMessage());
            }
        }

        $this->info("Successfully expired {$expiredCount} referral bonus(es).");

        return 0;
    }
}

