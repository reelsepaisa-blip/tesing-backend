<?php

namespace App\Services;

use App\Models\DriverIncentive;
use App\Models\DriverIncentiveProgress;
use App\Models\User;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DriverIncentiveService
{
    
    public function getDriverIncentives($driverId, $status = null)
    {
        $query = DriverIncentive::forDriver($driverId)
            ->active();

        if ($status) {
            $query->where('status', $status);
        }

        $incentives = $query->orderBy('start_time', 'desc')->get();

        return $incentives->map(function ($incentive) use ($driverId) {
            $progress = $incentive->calculateProgress($driverId);

            return [
                'id' => $incentive->id,
                'title' => $incentive->title,
                'description' => $incentive->description,
                'type' => $incentive->type,
                'status' => $incentive->status,
                'reward_amount' => $incentive->reward_amount,
                'start_time' => $incentive->start_time,
                'end_time' => $incentive->end_time,
                'time_remaining' => $incentive->getFormattedTimeRemaining(),
                'progress' => $progress,
                'milestones' => $incentive->milestones,
                'criteria' => $incentive->criteria,
                'is_live' => $incentive->isLive(),
                'is_upcoming' => $incentive->isUpcoming(),
                'is_completed' => $incentive->isCompleted(),
            ];
        })->values()->toArray();
    }

    
    public function getDriverIncentiveSummary($driverId, $filter = 'all', $date = null, $month = null, $year = null, $weekStart = null)
    {
        $query = DriverIncentive::forDriver($driverId)->active();
        $progressQuery = DriverIncentiveProgress::where('driver_id', $driverId)->where('is_completed', true);

        switch ($filter) {
            case 'daily':
                if ($date) {
                    $targetDate = Carbon::parse($date);
                    $query->whereDate('start_time', '<=', $targetDate)
                        ->whereDate('end_time', '>=', $targetDate);
                    $progressQuery->whereDate('completed_at', $targetDate);
                } else {
                    $today = Carbon::today();
                    $query->whereDate('start_time', '<=', $today)
                        ->whereDate('end_time', '>=', $today);
                    $progressQuery->whereDate('completed_at', $today);
                }
                break;

            case 'weekly':
                if ($weekStart) {
                    $weekStartDate = Carbon::parse($weekStart);
                    $weekEndDate = $weekStartDate->copy()->endOfWeek();
                } else {
                    $weekStartDate = Carbon::now()->startOfWeek();
                    $weekEndDate = Carbon::now()->endOfWeek();
                }
                $query->where(function ($q) use ($weekStartDate, $weekEndDate) {
                    $q->whereBetween('start_time', [$weekStartDate, $weekEndDate])
                        ->orWhereBetween('end_time', [$weekStartDate, $weekEndDate])
                        ->orWhere(function ($q2) use ($weekStartDate, $weekEndDate) {
                            $q2->where('start_time', '<=', $weekStartDate)
                                ->where('end_time', '>=', $weekEndDate);
                        });
                });
                $progressQuery->whereBetween('completed_at', [$weekStartDate, $weekEndDate]);
                break;

            case 'monthly':
                if ($month) {
                    $monthStart = Carbon::parse($month . '-01')->startOfMonth();
                    $monthEnd = Carbon::parse($month . '-01')->endOfMonth();
                } else {
                    $monthStart = Carbon::now()->startOfMonth();
                    $monthEnd = Carbon::now()->endOfMonth();
                }
                $query->where(function ($q) use ($monthStart, $monthEnd) {
                    $q->whereBetween('start_time', [$monthStart, $monthEnd])
                        ->orWhereBetween('end_time', [$monthStart, $monthEnd])
                        ->orWhere(function ($q2) use ($monthStart, $monthEnd) {
                            $q2->where('start_time', '<=', $monthStart)
                                ->where('end_time', '>=', $monthEnd);
                        });
                });
                $progressQuery->whereBetween('completed_at', [$monthStart, $monthEnd]);
                break;

            case 'yearly':
                if ($year) {
                    $yearStart = Carbon::createFromFormat('Y', $year)->startOfYear();
                    $yearEnd = Carbon::createFromFormat('Y', $year)->endOfYear();
                } else {
                    $yearStart = Carbon::now()->startOfYear();
                    $yearEnd = Carbon::now()->endOfYear();
                }
                $query->where(function ($q) use ($yearStart, $yearEnd) {
                    $q->whereBetween('start_time', [$yearStart, $yearEnd])
                        ->orWhereBetween('end_time', [$yearStart, $yearEnd])
                        ->orWhere(function ($q2) use ($yearStart, $yearEnd) {
                            $q2->where('start_time', '<=', $yearStart)
                                ->where('end_time', '>=', $yearEnd);
                        });
                });
                $progressQuery->whereBetween('completed_at', [$yearStart, $yearEnd]);
                break;
        }

        $incentives = $query->get();
        $progressRecords = DriverIncentiveProgress::where('driver_id', $driverId)
            ->get()
            ->keyBy('incentive_id');
        $totalEarned = $progressQuery->sum('total_earned');

        $liveIncentives = $incentives->filter(function ($incentive) {
            return $incentive->isLive();
        });

        $completedIncentives = $incentives->filter(function ($incentive) use ($progressRecords) {
            $progress = $progressRecords->get($incentive->id);

            if ($progress && $progress->is_completed) {
                return true;
            }

            return $incentive->isCompleted();
        });

        $upcomingIncentives = $incentives->filter(function ($incentive) {
            return $incentive->isUpcoming();
        });

        $periodEarned = $totalEarned;
        if ($filter === 'daily') {
            $today = $date ? Carbon::parse($date) : Carbon::today();
            $periodEarned = DriverIncentiveProgress::where('driver_id', $driverId)
                ->where('is_completed', true)
                ->whereDate('completed_at', $today)
                ->sum('total_earned');
        }

        return [
            'filter' => $filter,
            'period' => $this->getPeriodInfo($filter, $date, $month, $year, $weekStart),
            'total_earned' => $totalEarned,
            'period_earned' => $periodEarned,
            'live_incentives' => $this->formatIncentives($liveIncentives, $driverId, $progressRecords)->values()->toArray(),
            'completed_incentives' => $this->formatCompletedIncentives($completedIncentives, $driverId, $progressRecords)->values()->toArray(),
            'upcoming_incentives' => $this->formatIncentives($upcomingIncentives, $driverId, $progressRecords)->values()->toArray(),
            'total_live' => $liveIncentives->count(),
            'total_completed' => $completedIncentives->count(),
            'total_upcoming' => $upcomingIncentives->count(),
            'total_incentives' => $incentives->count(),
        ];
    }

    
    public function updateDriverProgress($driverId, $incentiveId, $progressData)
    {
        $incentive = DriverIncentive::find($incentiveId);
        if (!$incentive) {
            return false;
        }

        $progress = DriverIncentiveProgress::firstOrCreate(
            [
                'driver_id' => $driverId,
                'incentive_id' => $incentiveId,
            ],
            [
                'current_progress' => ['count' => 0],
                'milestone_progress' => [],
                'total_earned' => 0,
                'is_completed' => false,
            ]
        );

        $progress->updateProgress($progressData);

        $progress->checkCompletion($incentive);

        $this->updateMilestoneProgress($progress, $incentive);

        return $progress;
    }

    
    protected function updateMilestoneProgress($progress, $incentive)
    {
        if (!$incentive->milestones) {
            return;
        }

        $currentCount = $progress->current_progress['count'] ?? 0;
        $milestoneProgress = $progress->milestone_progress ?? [];

        foreach ($incentive->milestones as $milestone) {
            $milestoneId = $milestone['id'] ?? $milestone['target'];
            $target = $milestone['target'] ?? 0;
            $reward = $milestone['reward'] ?? 0;

            $wasAlreadyAchieved = isset($milestoneProgress[$milestoneId]) && 
                                 ($milestoneProgress[$milestoneId]['achieved'] ?? false);

            if ($currentCount >= $target && !$wasAlreadyAchieved) {
                $progress->updateMilestoneProgress($milestoneId, true, $reward);
            }
        }
    }

    
    public function processRideCompletion($driverId, $bookingId)
    {
        $booking = Booking::find($bookingId);
        if (!$booking || $booking->driver_id != $driverId || $booking->status !== 'completed') {
            return false;
        }

        $completionTime = $booking->completed_at ?? $booking->updated_at ?? now();

        $activeIncentives = DriverIncentive::forDriver($driverId)
            ->active()
            ->where('start_time', '<=', $completionTime)
            ->where('end_time', '>=', $completionTime)
            ->get();


        foreach ($activeIncentives as $incentive) {
            $this->updateIncentiveProgress($driverId, $incentive, $booking);
        }

        return true;
    }

    
    public function retroactivelyProcessRides($driverId, $incentiveId = null)
    {
        if ($incentiveId) {
            $incentives = DriverIncentive::forDriver($driverId)
                ->where('id', $incentiveId)
                ->active()
                ->get();
        } else {
            $incentives = DriverIncentive::forDriver($driverId)
                ->active()
                ->get();
        }

        if ($incentives->isEmpty()) {
            return ['processed' => 0, 'message' => 'No active incentives found for this driver'];
        }

        $processedCount = 0;

        foreach ($incentives as $incentive) {
            $qualifyingBookings = Booking::where('driver_id', $driverId)
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$incentive->start_time, $incentive->end_time])
                ->get()
                ->filter(function ($booking) use ($incentive) {
                    return $this->rideQualifiesForIncentive($incentive, $booking);
                });

            if ($qualifyingBookings->count() > 0) {
                $progress = DriverIncentiveProgress::firstOrCreate(
                    [
                        'driver_id' => $driverId,
                        'incentive_id' => $incentive->id,
                    ],
                    [
                        'current_progress' => ['count' => 0],
                        'milestone_progress' => [],
                        'total_earned' => 0,
                        'is_completed' => false,
                    ]
                );

                $progress->updateProgress(['count' => $qualifyingBookings->count()]);
                $progress->checkCompletion($incentive);
                $this->updateMilestoneProgress($progress, $incentive);
                $processedCount += $qualifyingBookings->count();
            }
        }

        return [
            'processed' => $processedCount,
            'incentives_processed' => $incentives->count(),
            'message' => "Processed {$processedCount} qualifying rides for {$incentives->count()} incentive(s)"
        ];
    }

    
    protected function updateIncentiveProgress($driverId, $incentive, $booking)
    {
        if (!$this->rideQualifiesForIncentive($incentive, $booking)) {
            return;
        }


        $progress = DriverIncentiveProgress::firstOrCreate(
            [
                'driver_id' => $driverId,
                'incentive_id' => $incentive->id,
            ],
            [
                'current_progress' => ['count' => 0, 'dates' => []],
                'milestone_progress' => [],
                'total_earned' => 0,
                'is_completed' => false,
            ]
        );

        // For streak incentives, track dates; for others, just count
        if ($incentive->type === 'streak') {
            $currentProgress = $progress->current_progress ?? ['count' => 0, 'dates' => []];
            $rideDate = Carbon::parse($booking->completed_at ?? $booking->updated_at ?? now())->format('Y-m-d');
            $dates = $currentProgress['dates'] ?? [];
            
            // Add date if not already present
            if (!in_array($rideDate, $dates)) {
                $dates[] = $rideDate;
                sort($dates);
            }
            
            $progress->updateProgress([
                'count' => count($dates),
                'dates' => $dates
            ]);
        } else {
            $currentCount = $progress->current_progress['count'] ?? 0;
            $progress->updateProgress(['count' => $currentCount + 1]);
        }

        $progress->checkCompletion($incentive);

        $this->updateMilestoneProgress($progress, $incentive);
    }

    
    protected function rideQualifiesForIncentive($incentive, $booking)
    {
        if ($incentive->zones && count($incentive->zones) > 0) {
            $zones = array_map('intval', $incentive->zones);
            $pickupZoneId = (int)($booking->pickup_zone_id ?? 0);
            $dropoffZoneId = (int)($booking->dropoff_zone_id ?? 0);
            
            if (!in_array($pickupZoneId, $zones) && !in_array($dropoffZoneId, $zones)) {
                return false;
            }
        }

        if ($incentive->ride_types && count($incentive->ride_types) > 0) {
            $rideTypes = array_map('intval', $incentive->ride_types);
            if (!in_array((int)$booking->ride_type_id, $rideTypes)) {
                return false;
            }
        }

        if ($incentive->time_slots && count($incentive->time_slots) > 0) {
            $rideTime = $booking->completed_at 
                ? Carbon::parse($booking->completed_at) 
                : ($booking->started_at 
                    ? Carbon::parse($booking->started_at) 
                    : Carbon::parse($booking->created_at));
            $qualifies = false;

            foreach ($incentive->time_slots as $slot) {
                $startTime = $slot['start'] ?? null;
                $endTime = $slot['end'] ?? null;

                if ($startTime === null && $endTime === null) {
                    $qualifies = true;
                    break;
                }

                if ($startTime === null || $endTime === null) {
                    continue;
                }

                try {
                    $start = Carbon::parse($startTime);
                    $end = Carbon::parse($endTime);

                    if ($rideTime->between($start, $end)) {
                        $qualifies = true;
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (!$qualifies) {
                return false;
            }
        }

        return true;
    }

    
    public function createIncentive($driverId, $data)
    {
        $incentive = DriverIncentive::create([
            'driver_id' => $driverId, // when null => global incentive for all drivers
            'title' => $data['title'],
            'description' => $data['description'],
            'type' => $data['type'],
            'criteria' => $data['criteria'],
            'reward_amount' => $data['reward_amount'],
            'status' => 'upcoming',
            'start_time' => Carbon::parse($data['start_time']),
            'end_time' => Carbon::parse($data['end_time']),
            'milestones' => $data['milestones'] ?? null,
            'zones' => $data['zones'] ?? null,
            'ride_types' => $data['ride_types'] ?? null,
            'time_slots' => $data['time_slots'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'meta_data' => $data['meta_data'] ?? null,
        ]);

        return $incentive;
    }

    
    public function getIncentiveGuidelines()
    {
        return [
            'Only completed rides (not canceled or rejected) are eligible for incentive calculation.',
            'Rides must be completed within the specified time slots to qualify.',
            'Only rides from eligible zones/cities will be counted.',
            'Incentives are earned by achieving specific milestones.',
            'Progress is calculated in real-time and updates automatically.',
            'Completed incentives are automatically credited to your earnings.',
        ];
    }

    
    protected function getPeriodInfo($filter, $date = null, $month = null, $year = null, $weekStart = null)
    {
        switch ($filter) {
            case 'daily':
                $targetDate = $date ? Carbon::parse($date) : Carbon::today();
                return [
                    'type' => 'daily',
                    'date' => $targetDate->format('Y-m-d'),
                    'formatted' => $targetDate->format('M d, Y'),
                    'start' => $targetDate->startOfDay()->format('Y-m-d H:i:s'),
                    'end' => $targetDate->endOfDay()->format('Y-m-d H:i:s'),
                ];

            case 'weekly':
                if ($weekStart) {
                    $weekStartDate = Carbon::parse($weekStart);
                } else {
                    $weekStartDate = Carbon::now()->startOfWeek();
                }
                $weekEndDate = $weekStartDate->copy()->endOfWeek();
                return [
                    'type' => 'weekly',
                    'week_start' => $weekStartDate->format('Y-m-d'),
                    'week_end' => $weekEndDate->format('Y-m-d'),
                    'formatted' => $weekStartDate->format('M d') . ' - ' . $weekEndDate->format('M d, Y'),
                ];

            case 'monthly':
                if ($month) {
                    $monthStart = Carbon::parse($month . '-01')->startOfMonth();
                } else {
                    $monthStart = Carbon::now()->startOfMonth();
                }
                $monthEnd = $monthStart->copy()->endOfMonth();
                return [
                    'type' => 'monthly',
                    'month' => $monthStart->format('Y-m'),
                    'month_start' => $monthStart->format('Y-m-d'),
                    'month_end' => $monthEnd->format('Y-m-d'),
                    'formatted' => $monthStart->format('F Y'),
                ];

            case 'yearly':
                if ($year) {
                    $yearStart = Carbon::createFromFormat('Y', $year)->startOfYear();
                } else {
                    $yearStart = Carbon::now()->startOfYear();
                }
                $yearEnd = $yearStart->copy()->endOfYear();
                return [
                    'type' => 'yearly',
                    'year' => $yearStart->format('Y'),
                    'year_start' => $yearStart->format('Y-m-d'),
                    'year_end' => $yearEnd->format('Y-m-d'),
                    'formatted' => $yearStart->format('Y'),
                ];

            default:
                return [
                    'type' => 'all',
                    'formatted' => 'All Time',
                ];
        }
    }

    
    protected function formatIncentives($incentives, $driverId, $progressRecords = null)
    {
        return $incentives->map(function ($incentive) use ($driverId, $progressRecords) {
            $progressData = $this->prepareProgressData($incentive, $driverId, $progressRecords);
            $isDriverCompleted = (bool)($progressData['completed_by_driver'] ?? false);
            $progress = Arr::except($progressData, ['completed_by_driver']);

            return [
                'id' => $incentive->id ?? '',
                'title' => $incentive->title ?? '',
                'description' => $incentive->description ?? '',
                'type' => $incentive->type ?? '',
                'status' => $incentive->status ?? '',
                'reward_amount' => $incentive->reward_amount ?? '',
                'start_time' => $incentive->start_time ?? '',
                'end_time' => $incentive->end_time ?? '',
                'time_remaining' => $incentive->isCompleted() ? null : ($incentive->getFormattedTimeRemaining() ?? ''),
                'progress' => $progress ?? '',
                'milestones' => $incentive->milestones ?? '',
                'criteria' => $incentive->criteria ?? '',
                'is_live' => $incentive->isLive() ?? '',
                'is_upcoming' => $incentive->isUpcoming() ?? '',
                'is_completed' => ($progress['is_completed'] ?? false) || $incentive->isCompleted(),
            ];
        });
    }

    protected function formatCompletedIncentives($incentives, $driverId, $progressRecords = null)
    {
        return $incentives->map(function ($incentive) use ($driverId, $progressRecords) {
            $progressData = $this->prepareProgressData($incentive, $driverId, $progressRecords);
            $progress = Arr::except($progressData, ['completed_by_driver']);
            $milestones = collect($progress['milestones_achieved'] ?? []);

            if ($milestones->isEmpty()) {
                return [[
                    'current_count' => $progress['current_count'] ?? 0,
                    'target_count' => $progress['target_count'] ?? 0,
                    'progress_percentage' => $progress['progress_percentage'] ?? 0,
                    'complete_ride_target' => null,
                    'achieved' => $progress['is_completed'] ?? false,
                    'reward_earned' => $progress['total_earned'] ?? null,
                    'achieved_at' => $progress['completed_at'] ?? null,
                ]];
            }

            return $milestones->map(function ($milestone) use ($progress) {
                return [
                    'current_count' => $progress['current_count'] ?? 0,
                    'target_count' => $progress['target_count'] ?? 0,
                    'progress_percentage' => $progress['progress_percentage'] ?? 0,
                    'complete_ride_target' => $milestone['complete_ride_target'] ?? null,
                    'achieved' => $milestone['achieved'] ?? false,
                    'reward_earned' => $milestone['reward_earned'] ?? null,
                    'achieved_at' => $milestone['achieved_at'] ?? null,
                ];
            })->values()->all();
        })->flatten(1);
    }

    protected function prepareProgressData($incentive, $driverId, $progressRecords = null): array
    {
        $progress = $incentive->calculateProgress($driverId);
        $progressRecord = $progressRecords ? $progressRecords->get($incentive->id) : null;
        $isDriverCompleted = (bool)($progress['is_completed'] ?? false);

        if ($progressRecord) {
            $progress['total_earned'] = $progressRecord->total_earned;
            $progress['completed_at'] = optional($progressRecord->completed_at)->toDateTimeString();
        } elseif (!array_key_exists('completed_at', $progress)) {
            $progress['completed_at'] = null;
        }

        if ($isDriverCompleted) {
            $target = $progress['target_count'] ?? 0;
            $current = $progress['current_count'] ?? 0;

            if ($target > 0 && $current < $target) {
                $progress['current_count'] = $target;
            }

            $progress['progress_percentage'] = 100.00;
        }

        $progress = $this->normalizeMilestoneAchievements($progress, $incentive);
        $progress['is_completed'] = ($progress['is_completed'] ?? false) || $incentive->isCompleted();
        $progress['completed_by_driver'] = $isDriverCompleted;

        return $progress;
    }

    
    protected function normalizeMilestoneAchievements(array $progress, $incentive): array
    {
        $rawMilestones = $progress['milestones_achieved'] ?? [];

        $milestoneDefinitions = collect($incentive->milestones ?? [])
            ->mapWithKeys(function ($definition) {
                $id = $definition['id'] ?? $definition['target'] ?? null;

                if ($id === null) {
                    return [];
                }

                return [$id => $definition];
            });

        $formattedFromProgress = collect();

        if (!empty($rawMilestones)) {
            if (Arr::isAssoc($rawMilestones)) {
                $formattedFromProgress = collect($rawMilestones)->map(function ($data, $milestoneId) use ($milestoneDefinitions) {
                    return $this->formatSingleMilestoneAchievement($milestoneId, $data, $milestoneDefinitions->get($milestoneId));
                });
            } else {
                $formattedFromProgress = collect($rawMilestones)->map(function ($milestoneId) use ($milestoneDefinitions) {
                    return $this->formatSingleMilestoneAchievement($milestoneId, ['achieved' => true], $milestoneDefinitions->get($milestoneId));
                });
            }
        }

        $formattedByTarget = $formattedFromProgress->keyBy(function ($item) {
            $target = $item['complete_ride_target'];
            return is_numeric($target) ? (string)$target : $target;
        });

        if ($milestoneDefinitions->isNotEmpty()) {
            $formatted = $milestoneDefinitions
                ->map(function ($definition) use ($formattedByTarget) {
                    $target = $definition['target'] ?? $definition['id'] ?? null;

                    if ($target === null) {
                        return null;
                    }

                    $key = is_numeric($target) ? (string)$target : $target;
                    $entry = $formattedByTarget->get($key);

                    if ($entry) {
                        if ((empty($entry['reward_earned']) || $entry['reward_earned'] === null) && isset($definition['reward'])) {
                            $entry['reward_earned'] = (string)$definition['reward'];
                        }

                        return $entry;
                    }

                    $reward = $definition['reward'] ?? null;

                    return [
                        'complete_ride_target' => is_numeric($target) ? (int)$target : $target,
                        'achieved' => false,
                        'reward_earned' => $reward !== null ? (string)$reward : null,
                        'achieved_at' => null,
                    ];
                })
                ->filter()
                ->values();
        } else {
            $formatted = $formattedByTarget->values();
        }

        if ($formattedByTarget->isNotEmpty()) {
            $formatted = $formatted->merge(
                $formattedByTarget->reject(function ($entry) use ($formatted) {
                    $key = is_numeric($entry['complete_ride_target'])
                        ? (string)$entry['complete_ride_target']
                        : $entry['complete_ride_target'];

                    return $formatted->contains(function ($item) use ($key) {
                        $itemKey = is_numeric($item['complete_ride_target'])
                            ? (string)$item['complete_ride_target']
                            : $item['complete_ride_target'];

                        return $itemKey === $key;
                    });
                })
            );
        }

        $progress['milestones_achieved'] = $formatted
            ->sortBy('complete_ride_target')
            ->values()
            ->toArray();

        return $progress;
    }

    
    protected function formatSingleMilestoneAchievement($milestoneId, array $data, $definition = null): array
    {
        $target = $definition['target'] ?? $milestoneId;
        $reward = $data['reward_earned'] ?? ($definition['reward'] ?? null);
        $achievedAt = $data['achieved_at'] ?? null;

        $rewardValue = $reward !== null ? (string)$reward : null;

        return [
            'complete_ride_target' => is_numeric($target) ? (int)$target : $target,
            'achieved' => (bool)($data['achieved'] ?? false),
            'reward_earned' => $rewardValue,
            'achieved_at' => $achievedAt,
        ];
    }
}
