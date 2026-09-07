<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class DriverIncentiveProgress extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'incentive_id',
        'current_progress',
        'milestone_progress',
        'total_earned',
        'is_completed',
        'completed_at',
        'meta_data',
    ];

    protected $casts = [
        'current_progress' => 'array',
        'milestone_progress' => 'array',
        'total_earned' => 'decimal:2',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'meta_data' => 'array',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function incentive()
    {
        return $this->belongsTo(DriverIncentive::class, 'incentive_id');
    }

    public function updateProgress($progressData)
    {
        $this->current_progress = array_merge($this->current_progress ?? [], $progressData);
        $this->save();
    }

    public function updateMilestoneProgress($milestoneId, $achieved = true, $rewardEarned = 0)
    {
        $milestoneProgress = $this->milestone_progress ?? [];

        $wasAlreadyAchieved = isset($milestoneProgress[$milestoneId]) &&
            ($milestoneProgress[$milestoneId]['achieved'] ?? false);

        $milestoneProgress[$milestoneId] = [
            'achieved' => $achieved,
            'reward_earned' => $rewardEarned,
            'achieved_at' => $achieved ? Carbon::now() : null
        ];

        $this->milestone_progress = $milestoneProgress;

        if (!$wasAlreadyAchieved && $achieved && $rewardEarned > 0) {
            $this->total_earned += $rewardEarned;
        }

        $this->save();

        if ($achieved && $rewardEarned > 0 && !$wasAlreadyAchieved) {
            $incentive = $this->incentive;
            $incentiveTitle = $incentive ? $incentive->title : "Incentive #{$this->incentive_id}";
            $description = "Milestone reward: ₹{$rewardEarned} for {$incentiveTitle}";

            $this->creditRewardToWallet($rewardEarned, $description, [
                'milestone_id' => $milestoneId,
                'incentive_id' => $this->incentive_id,
                'incentive_title' => $incentive ? $incentive->title : null,
                'reward_type' => 'milestone',
                'milestone_reward' => $rewardEarned,
            ]);
        }
    }

    public function checkCompletion($incentive)
    {
        $incentiveType = $incentive->type ?? 'ride_count';
        $isCompleted = false;

        // Handle streak incentives differently
        if ($incentiveType === 'streak') {
            $requiredConsecutiveDays = $incentive->criteria['consecutive_days'] ?? 0;
            $consecutiveDays = $this->calculateConsecutiveDays($incentive);

            if ($consecutiveDays >= $requiredConsecutiveDays && !$this->is_completed) {
                $isCompleted = true;
            }
        } else {
            // For other incentive types, use target count
            $target = $incentive->criteria['target'] ?? 0;
            $currentCount = $this->current_progress['count'] ?? 0;

            if ($currentCount >= $target && !$this->is_completed) {
                $isCompleted = true;
            }
        }

        if ($isCompleted) {
            $this->is_completed = true;
            $this->completed_at = Carbon::now();

            $mainRewardAmount = $incentive->reward_amount ?? 0;
            $metaData = $this->meta_data ?? [];
            $mainRewardCredited = $metaData['main_reward_credited'] ?? false;

            $hasMilestones = !empty($incentive->milestones);

            if ($mainRewardAmount > 0 && !$mainRewardCredited && !$hasMilestones) {
                $this->total_earned += $mainRewardAmount;
                $this->creditRewardToWallet($mainRewardAmount, "Incentive reward for completing: {$incentive->title}", [
                    'incentive_id' => $incentive->id,
                    'incentive_title' => $incentive->title,
                    'reward_type' => 'main',
                    'main_reward' => $mainRewardAmount,
                ]);

                $metaData['main_reward_credited'] = true;
                $metaData['main_reward_credited_at'] = Carbon::now()->toDateTimeString();
                $this->meta_data = $metaData;
            }

            $this->save();

            return true;
        }

        return false;
    }

    /**
     * Calculate consecutive days with completed rides for streak incentive
     */
    protected function calculateConsecutiveDays($incentive): int
    {
        // First, try to use stored dates from progress (more efficient)
        $storedDates = $this->current_progress['dates'] ?? null;

        if ($storedDates && is_array($storedDates) && count($storedDates) > 0) {
            $datesWithRides = collect($storedDates)->sort()->values();
        } else {
            // Fallback: calculate from bookings (slower but more accurate)
            $driverId = $this->driver_id;
            $startTime = $incentive->start_time;
            $endTime = $incentive->end_time;

            // Get all completed bookings for this driver within incentive period
            $bookings = \App\Models\Booking::where('driver_id', $driverId)
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$startTime, $endTime])
                ->get();

            // Filter bookings that qualify for this incentive
            $qualifyingBookings = $bookings->filter(function ($booking) use ($incentive) {
                $incentiveService = app(\App\Services\DriverIncentiveService::class);
                return $incentiveService->rideQualifiesForIncentive($incentive, $booking);
            });

            // Get unique dates with at least one completed ride
            $datesWithRides = $qualifyingBookings->map(function ($booking) {
                return Carbon::parse($booking->completed_at)->format('Y-m-d');
            })->unique()->sort()->values();
        }

        if ($datesWithRides->isEmpty()) {
            return 0;
        }

        // Calculate longest streak of consecutive days
        $maxStreak = 1;
        $currentStreak = 1;
        $previousDate = Carbon::parse($datesWithRides->first());

        foreach ($datesWithRides->skip(1) as $dateStr) {
            $currentDate = Carbon::parse($dateStr);
            $daysDiff = $previousDate->diffInDays($currentDate);

            if ($daysDiff == 1) {
                // Consecutive day
                $currentStreak++;
                $maxStreak = max($maxStreak, $currentStreak);
            } else {
                // Streak broken
                $currentStreak = 1;
            }

            $previousDate = $currentDate;
        }

        return $maxStreak;
    }

    public function getProgressPercentage()
    {
        $target = $this->incentive->criteria['target'] ?? 0;
        $current = $this->current_progress['count'] ?? 0;

        return $target > 0 ? round(($current / $target) * 100, 2) : 0;
    }

    public function getMilestonesAchieved()
    {
        if (!$this->milestone_progress) {
            return [];
        }

        return array_filter($this->milestone_progress, function ($milestone) {
            return $milestone['achieved'] ?? false;
        });
    }

    public function getTotalMilestoneRewards()
    {
        $total = 0;
        if ($this->milestone_progress) {
            foreach ($this->milestone_progress as $milestone) {
                if ($milestone['achieved'] ?? false) {
                    $total += $milestone['reward_earned'] ?? 0;
                }
            }
        }

        return $total;
    }


    protected function creditRewardToWallet(float $amount, string $description, array $metaData = [])
    {
        if ($amount <= 0) {
            return null;
        }

        $driver = $this->driver;
        if (!$driver) {
            return null;
        }

        try {
            $walletService = app(\App\Services\WalletService::class);
            $wallet = $walletService->ensureWallet($driver);

            if (!$wallet->isActive()) {
                return null;
            }

            $walletTransaction = $wallet->credit(
                $amount,
                \App\Models\WalletTransaction::TYPE_INCENTIVE_REWARD,
                $description,
                array_merge($metaData, [
                    'driver_id' => $driver->id,
                    'incentive_progress_id' => $this->id,
                    'credited_at' => Carbon::now()->toDateTimeString(),
                ])
            );

            return $walletTransaction;
        } catch (\Exception $e) {
            return null;
        }
    }
}
