<?php

namespace App\Filament\Widgets;

use App\Models\PromoCode;
use App\Models\PromoUsage;
use App\Models\ReferralBonus;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class PromoStats extends BaseWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $promoCodes = PromoCode::query();
        $promoUsages = PromoUsage::query();
        $referralBonuses = ReferralBonus::query();

        $todayUsage = $promoUsages->clone()
            ->whereDate('created_at', today())
            ->selectRaw('
                COUNT(*) as total_uses,
                SUM(discount_amount) as total_discount
            ')
            ->first();

        $activePromos = $promoCodes->clone()
            ->where(function ($query) {
                $query->where('status', PromoCode::STATUS_ACTIVE)
                    ->orWhere('status', 1); // Handle legacy integer status (1 = active)
            })
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->count();

        $pendingBonuses = $referralBonuses->clone()
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) as count, SUM(amount) as amount')
            ->first();

        $days = 7;
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();

        $dates = collect(range(0, $days - 1))
            ->map(fn($i) => $start->copy()->addDays($i)->format('Y-m-d'))
            ->all();

        $trends = $promoUsages->clone()
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as uses'),
                DB::raw('SUM(discount_amount) as discount')
            )
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $usageTrend = array_map(
            fn($date) => (int) ($trends->get($date)?->uses ?? 0),
            $dates
        );

        $discountTrend = array_map(
            fn($date) => (float) ($trends->get($date)?->discount ?? 0),
            $dates
        );

        return [
            Stat::make("Today's Promo Uses", $todayUsage->total_uses ?? 0)
                ->description('₹' . number_format($todayUsage->total_discount ?? 0, 2) . ' total discount')
                ->descriptionIcon('heroicon-m-ticket')
                ->chart($usageTrend)
                ->color('success'),

            Stat::make('Active Promo Codes', $activePromos)
                ->description('Currently valid codes')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('info'),

            Stat::make('Pending Referral Bonuses', $pendingBonuses->count ?? 0)
                ->description('₹' . number_format($pendingBonuses->amount ?? 0, 2) . ' total pending')
                ->descriptionIcon('heroicon-m-clock')
                ->chart($discountTrend)
                ->color('warning'),
        ];
    }
}
