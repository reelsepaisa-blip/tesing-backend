<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\PromoCode;
use App\Models\PromoUsage;
use App\Models\ReferralBonus;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PromoService
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    
    public function generateCode(int $length = 8): string
    {
        do {
            $code = strtoupper(Str::random($length));
        } while (PromoCode::where('code', $code)->exists());

        return $code;
    }

    
    private function generateNameBasedReferralCode(string $name): string
    {
        $cleanName = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));

        $namePrefix = substr($cleanName, 0, 3);

        if (strlen($namePrefix) < 3) {
            $namePrefix = str_pad($namePrefix, 3, 'X', STR_PAD_RIGHT);
        }

        do {
            $code = $namePrefix . rand(1000, 9999);
        } while (PromoCode::where('code', $code)->exists());

        return $code;
    }

    
    public function generateReferralCode(User $user): PromoCode
    {
        return DB::transaction(function () use ($user) {
            $code = $this->generateNameBasedReferralCode($user->name);

            return PromoCode::create([
                'code' => $code,
                'description' => "Referral code for {$user->name}",
                'type' => PromoCode::TYPE_FIXED,
                'value' => config('referral.referred_discount', 100),
                'max_uses_per_user' => 1,
                'status' => PromoCode::STATUS_ACTIVE,
                'is_first_ride_only' => true,
                'is_referral_code' => true,
                'referral_user_id' => $user->id,
                'meta_data' => [
                    'referrer_bonus' => config('referral.referrer_bonus', 50),
                    'referred_bonus' => config('referral.referred_bonus', 50),
                ],
            ]);
        });
    }

    
    public function applyPromoCode(Booking $booking, PromoCode $promoCode): PromoUsage
    {
        return DB::transaction(function () use ($booking, $promoCode) {
            if (!$promoCode->isValidForBooking($booking)) {
                throw new \InvalidArgumentException('Invalid promo code');
            }

            $discount = $promoCode->calculateDiscount($booking->total_fare);

            $usage = PromoUsage::create([
                'promo_code_id' => $promoCode->id,
                'user_id' => $booking->user_id,
                'booking_id' => $booking->id,
                'original_amount' => $booking->total_fare,
                'discount_amount' => $discount,
                'final_amount' => $booking->total_fare - $discount,
                'meta_data' => [
                    'promo_type' => $promoCode->type,
                    'promo_value' => $promoCode->value,
                    'is_referral' => $promoCode->is_referral_code,
                ],
            ]);

            $booking->update([
                'promo_code_id' => $promoCode->id,
                'discount_amount' => $discount,
                'final_amount' => $booking->total_fare - $discount,
            ]);

            if ($promoCode->type === PromoCode::TYPE_CASHBACK) {
                $this->walletService->credit(
                    wallet: $booking->user->wallet,
                    amount: $discount,
                    description: "Cashback from booking #{$booking->booking_code}",
                    reference: $usage,
                    type: 'promo_cashback'
                );
            }

            if ($promoCode->is_referral_code) {
                $this->createReferralBonuses($booking->user, $promoCode->referralUser);
            }

            return $usage;
        });
    }

    
    protected function createReferralBonuses(User $referred, User $referrer): void
    {
        // Ensure both users have the same role_id
        if ($referred->role_id !== $referrer->role_id) {
            return;
        }

        $settings = \App\Models\DriverSearchSetting::getActive();
        $referrerReward = $settings->getReferrerReward($referred->role_id);
        $referredReward = $settings->getReferredReward($referred->role_id);

        ReferralBonus::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'type' => 'referrer_bonus',
            'amount' => $referrerReward,
            'status' => ReferralBonus::STATUS_PENDING,
            'expires_at' => now()->addDays(config('referral.bonus_expiry_days', 30)),
            'meta_data' => [
                'referred_name' => $referred->name,
                'referred_phone' => $referred->phone_number,
            ],
        ]);

        ReferralBonus::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'type' => 'referred_bonus',
            'amount' => $referredReward,
            'status' => ReferralBonus::STATUS_PENDING,
            'expires_at' => now()->addDays(config('referral.bonus_expiry_days', 30)),
            'meta_data' => [
                'referrer_name' => $referrer->name,
                'referrer_phone' => $referrer->phone_number,
            ],
        ]);
    }

    
    public function processReferralBonuses(User $user): void
    {
        $bonuses = ReferralBonus::where(function ($query) use ($user) {
            $query->where('referrer_id', $user->id)
                ->orWhere('referred_id', $user->id);
        })
            ->where('status', ReferralBonus::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->get();

        foreach ($bonuses as $bonus) {
            DB::transaction(function () use ($bonus) {
                if (!$bonus->markAsCredited()) {
                    return;
                }

                $recipient = match ($bonus->type) {
                    'referrer_bonus' => $bonus->referrer,
                    'referred_bonus' => $bonus->referred,
                };

                $this->walletService->credit(
                    wallet: $recipient->wallet,
                    amount: $bonus->amount,
                    description: "Referral bonus - {$bonus->type}",
                    reference: $bonus,
                    type: 'referral_bonus'
                );

                if ($bonus->type === 'referrer_bonus' && $bonus->referrer) {
                    $this->maybeAwardTierBonus($bonus->referrer);
                }
            });
        }
    }

    
    protected function maybeAwardTierBonus(User $referrer): void
    {
        $creditedCount = ReferralBonus::where('referrer_id', $referrer->id)
            ->where('type', 'referrer_bonus')
            ->where('status', ReferralBonus::STATUS_CREDITED)
            ->count();

        $tier = \App\Models\ReferralTier::where('is_active', true)
            ->where('milestone_count', $creditedCount)
            ->first();

        if (!$tier) {
            return;
        }

        $alreadyGranted = ReferralBonus::where('referrer_id', $referrer->id)
            ->where('type', 'tier_bonus')
            ->where('status', ReferralBonus::STATUS_CREDITED)
            ->where('meta_data->milestone', $creditedCount)
            ->exists();

        if ($alreadyGranted) {
            return;
        }

        $tierBonus = ReferralBonus::create([
            'referrer_id' => $referrer->id,
            'referred_id' => null,
            'type' => 'tier_bonus',
            'amount' => $tier->bonus_amount,
            'status' => ReferralBonus::STATUS_CREDITED,
            'credited_at' => now(),
            'expires_at' => now()->addDays(30),
            'meta_data' => [
                'milestone' => $creditedCount,
                'tier_id' => $tier->id,
            ],
        ]);

        $this->walletService->credit(
            wallet: $referrer->wallet,
            amount: $tier->bonus_amount,
            description: "Referral tier bonus (milestone: {$creditedCount})",
            reference: $tierBonus,
            type: 'referral_bonus'
        );
    }

    
    public function getReferralStats(User $user): array
    {
        $referralCode = PromoCode::where('referral_user_id', $user->id)
            ->where('is_referral_code', true)
            ->first();

        $referredUsers = User::where('referred_by', $user->id)->count();

        $bonuses = ReferralBonus::where('referrer_id', $user->id)
            ->selectRaw('
                COUNT(*) as total_count,
                COUNT(CASE WHEN status = "credited" THEN 1 END) as credited_count,
                COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_count,
                SUM(CASE WHEN status = "credited" THEN amount ELSE 0 END) as total_earned
            ')
            ->first();

        return [
            'referral_code' => $referralCode?->code,
            'referred_users' => $referredUsers,
            'total_bonuses' => $bonuses->total_count,
            'credited_bonuses' => $bonuses->credited_count,
            'pending_bonuses' => $bonuses->pending_count,
            'total_earned' => $bonuses->total_earned ?? 0,
        ];
    }
}
