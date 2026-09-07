<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverIncentive;
use App\Models\DriverIncentiveProgress;
use App\Services\DriverIncentiveService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class DriverIncentiveController extends Controller
{
    protected $incentiveService;

    public function __construct(DriverIncentiveService $incentiveService)
    {
        $this->incentiveService = $incentiveService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $driverId = Auth::id();

            // Support both ?filter=daily&date=YYYY-MM-DD and ?daily=YYYY-MM-DD formats
            $dailyParam = $request->query('daily');
            if ($dailyParam) {
                $filter = 'daily';
                $date = $dailyParam;
            } else {
                $filter = $request->query('filter', 'daily');  // daily or weekly
                $date = $request->query('date', Carbon::today()->format('Y-m-d'));
            }

            // Get summary data for earnings
            if ($filter === 'daily') {
                $summary = $this->incentiveService->getDriverIncentiveSummary($driverId, 'daily', $date);
            } else {
                $weekStart = $request->query('week_start', Carbon::now()->startOfWeek()->format('Y-m-d'));
                $summary = $this->incentiveService->getDriverIncentiveSummary($driverId, 'weekly', null, null, null, $weekStart);
            }

            // Filter completed incentives by selected date
            $targetDate = $filter === 'daily' ? Carbon::parse($date) : null;
            $weekStartDate = $filter === 'weekly' ? Carbon::parse($request->query('week_start', Carbon::now()->startOfWeek()->format('Y-m-d'))) : null;
            $weekEndDate = $weekStartDate ? $weekStartDate->copy()->endOfWeek() : null;

            // Get all incentives and format them consistently
            $allLiveIncentives = $this->incentiveService->getDriverIncentives($driverId, 'live');
            $allUpcomingIncentives = $this->incentiveService->getDriverIncentives($driverId, 'upcoming');

            // Filter live and upcoming incentives by date range
            if ($filter === 'daily' && $targetDate) {
                $liveIncentives = collect($allLiveIncentives)->filter(function ($incentive) use ($targetDate) {
                    $startTime = Carbon::parse($incentive['start_time'] ?? now());
                    $endTime = Carbon::parse($incentive['end_time'] ?? now());
                    // Check if target date falls within the incentive's date range (same logic as whereDate)
                    return $startTime->format('Y-m-d') <= $targetDate->format('Y-m-d') &&
                        $endTime->format('Y-m-d') >= $targetDate->format('Y-m-d');
                })->values()->toArray();

                $upcomingIncentives = collect($allUpcomingIncentives)->filter(function ($incentive) use ($targetDate) {
                    $startTime = Carbon::parse($incentive['start_time'] ?? now());
                    $endTime = Carbon::parse($incentive['end_time'] ?? now());
                    // Check if target date falls within the incentive's date range (same logic as whereDate)
                    return $startTime->format('Y-m-d') <= $targetDate->format('Y-m-d') &&
                        $endTime->format('Y-m-d') >= $targetDate->format('Y-m-d');
                })->values()->toArray();
            } elseif ($filter === 'weekly' && $weekStartDate && $weekEndDate) {
                $liveIncentives = collect($allLiveIncentives)->filter(function ($incentive) use ($weekStartDate, $weekEndDate) {
                    $startTime = Carbon::parse($incentive['start_time'] ?? now());
                    $endTime = Carbon::parse($incentive['end_time'] ?? now());
                    // Check if incentive overlaps with the week
                    return ($startTime->lte($weekEndDate) && $endTime->gte($weekStartDate));
                })->values()->toArray();

                $upcomingIncentives = collect($allUpcomingIncentives)->filter(function ($incentive) use ($weekStartDate, $weekEndDate) {
                    $startTime = Carbon::parse($incentive['start_time'] ?? now());
                    $endTime = Carbon::parse($incentive['end_time'] ?? now());
                    // Check if incentive overlaps with the week
                    return ($startTime->lte($weekEndDate) && $endTime->gte($weekStartDate));
                })->values()->toArray();
            } else {
                $liveIncentives = $allLiveIncentives;
                $upcomingIncentives = $allUpcomingIncentives;
            }

            // Get progress records filtered by date for completed incentives
            $progressQuery = DriverIncentiveProgress::where('driver_id', $driverId)
                ->where('is_completed', true);

            if ($filter === 'daily' && $targetDate) {
                $progressQuery->whereDate('completed_at', $targetDate);
            } elseif ($filter === 'weekly' && $weekStartDate && $weekEndDate) {
                $progressQuery->whereBetween('completed_at', [$weekStartDate, $weekEndDate]);
            }

            $progressRecords = $progressQuery->get()->keyBy('incentive_id');

            // Get completed incentive IDs that match the date filter
            $completedIncentiveIds = $progressRecords->pluck('incentive_id')->toArray();

            // Get all completed incentives, then filter by date
            $allCompletedIncentives = $this->incentiveService->getDriverIncentives($driverId, 'completed');
            $completedIncentives = collect($allCompletedIncentives)->filter(function ($incentive) use ($completedIncentiveIds) {
                return in_array($incentive['id'], $completedIncentiveIds);
            })->values()->toArray();

            // Separate live incentives into actually live and driver-completed
            // Only include non-completed live incentives
            $actualLiveIncentives = collect($liveIncentives)->filter(function ($incentive) {
                $progress = $incentive['progress'] ?? [];
                // Only include if NOT completed
                return !($progress['is_completed'] ?? false);
            })->values()->toArray();

            // Filter driver-completed live incentives by completion date
            // Only show completed incentives on the date they were completed
            $driverCompletedLive = collect($liveIncentives)->filter(function ($incentive) use ($progressRecords) {
                $progress = $incentive['progress'] ?? [];
                $isCompleted = ($progress['is_completed'] ?? false);
                if ($isCompleted && isset($incentive['id'])) {
                    // Only include if completed on the target date (progressRecords is already filtered by date)
                    return $progressRecords->has($incentive['id']);
                }
                return false;
            })->values()->toArray();

            // Format all incentives for UI (Image 1 format)
            $allIncentives = collect()
                ->merge($actualLiveIncentives)
                ->merge($upcomingIncentives)
                ->merge($driverCompletedLive)
                ->merge($completedIncentives);

            $formattedIncentives = $allIncentives->map(function ($incentive) use ($progressRecords) {
                // Add completion data for completed incentives
                $progress = $incentive['progress'] ?? [];
                $isCompleted = ($progress['is_completed'] ?? false) || ($incentive['is_completed'] ?? false);

                if ($isCompleted && isset($incentive['id'])) {
                    $progressRecord = $progressRecords->get($incentive['id']);
                    if ($progressRecord) {
                        $incentive['progress']['completed_at'] = $progressRecord->completed_at ? $progressRecord->completed_at->toDateTimeString() : null;
                        if (!isset($incentive['progress']['total_earned'])) {
                            $incentive['progress']['total_earned'] = $progressRecord->total_earned;
                        }
                    }
                }
                return $this->formatIncentiveCard($incentive, $progressRecords);
            })->values();

            // Generate date selector for daily view
            $dateSelector = [];
            if ($filter === 'daily') {
                $currentDate = Carbon::parse($date);
                for ($i = -3; $i <= 7; $i++) {
                    $selectorDate = $currentDate->copy()->addDays($i);
                    $dateSelector[] = [
                        'date' => $selectorDate->format('Y-m-d'),
                        'formatted' => $selectorDate->format('j M'),
                        'is_selected' => $selectorDate->format('Y-m-d') === $currentDate->format('Y-m-d'),
                        'is_today' => $selectorDate->isToday(),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'filter' => $filter,
                    'current_date' => $filter === 'daily' ? $date : ($summary['period']['week_start'] ?? null),
                    'date_selector' => $dateSelector,
                    'total_incentive_earning' => number_format($summary['period_earned'] ?? $summary['total_earned'] ?? 0, 2, '.', ''),
                    'incentives' => $formattedIncentives,
                    'summary' => [
                        'total_earned' => $summary['total_earned'] ?? 0,
                        'period_earned' => $summary['period_earned'] ?? 0,
                        'total_live' => $summary['total_live'] ?? 0,
                        'total_completed' => $summary['total_completed'] ?? 0,
                        'total_upcoming' => $summary['total_upcoming'] ?? 0,
                    ],
                ],
                'message' => 'Incentive data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve incentive summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function formatIncentiveCard($incentive, $progressRecords = null)
    {
        $status = $incentive['status'] ?? 'live';
        $isLive = $incentive['is_live'] ?? false;
        $progress = $incentive['progress'] ?? [];
        $isCompleted = ($progress['is_completed'] ?? false) || ($incentive['is_completed'] ?? false);
        $isUpcoming = $incentive['is_upcoming'] ?? false;

        // Determine status badge
        if ($isCompleted) {
            $statusBadge = 'completed';
        } elseif ($isLive) {
            $statusBadge = 'live';
        } elseif ($isUpcoming) {
            $statusBadge = 'upcoming';
        } else {
            $statusBadge = $status;
        }

        $currentCount = $progress['current_count'] ?? 0;
        $targetCount = $progress['target_count'] ?? 0;
        $progressPercentage = $progress['progress_percentage'] ?? 0;

        // Format description
        $description = '';
        if ($isLive && $targetCount > $currentCount) {
            $remaining = $targetCount - $currentCount;
            $description = "Complete {$remaining} More Rides and Get a \$" . number_format($incentive['reward_amount'] ?? 0, 2) . ' Bonus.';
        } elseif ($isUpcoming) {
            $description = 'Get a $' . number_format($incentive['reward_amount'] ?? 0, 2) . ' Bonus when you hit your target.';
        }

        // Format deadline/time info based on exact status conditions
        $timeInfo = null;
        $timeLabel = '';

        // Priority 1: If is_completed: true (regardless of is_live) → show completed_at
        if ($isCompleted && !$isUpcoming) {
            $completedAt = $progress['completed_at'] ?? null;
            if (!$completedAt && $progressRecords && isset($incentive['id'])) {
                $progressRecord = $progressRecords->get($incentive['id']);
                if ($progressRecord && $progressRecord->completed_at) {
                    $completedAt = $progressRecord->completed_at->toDateTimeString();
                }
            }
            if ($completedAt) {
                $timeInfo = is_string($completedAt) ? $completedAt : Carbon::parse($completedAt)->toDateTimeString();
            }
        }
        // Priority 2: If is_live: true AND is_completed: false AND is_upcoming: false → show end_time
        elseif ($isLive && !$isCompleted && !$isUpcoming) {
            $endTime = Carbon::parse($incentive['end_time'] ?? now());
            $timeInfo = $endTime->toDateTimeString();
        }
        // Priority 3: If is_live: false AND is_completed: false AND is_upcoming: true → show start_time
        elseif (!$isLive && !$isCompleted && $isUpcoming) {
            $startTime = Carbon::parse($incentive['start_time'] ?? now());
            $timeInfo = $startTime->toDateTimeString();
        }

        // Format earned amount for completed
        $earnedAmount = '';
        if ($isCompleted) {
            $earnedAmount = '$' . number_format($progress['total_earned'] ?? $incentive['reward_amount'] ?? 0, 1);
        }

        // Format milestones for UI (same format as show method)
        $formattedMilestones = [];
        $milestones = $incentive['milestones'] ?? [];
        $milestoneProgress = $progress['milestones_achieved'] ?? [];
        $incentiveAchievedAt = null; // Track achieved_at for the incentive

        if (!empty($milestones)) {
            foreach ($milestones as $milestone) {
                $milestoneTarget = $milestone['target'] ?? 0;
                $milestoneId = $milestone['id'] ?? $milestoneTarget;
                $milestoneReward = $milestone['reward'] ?? 0;

                // Find milestone progress
                $achieved = false;
                $rewardEarned = 0;
                $achievedAt = null;

                // Handle both array and object formats of milestoneProgress
                if (is_array($milestoneProgress)) {
                    // Check if it's an associative array (object format)
                    $isAssoc = !empty($milestoneProgress) && array_keys($milestoneProgress) !== range(0, count($milestoneProgress) - 1);

                    if ($isAssoc) {
                        // Object format: {"1": {"achieved": true, ...}}
                        $mpKey = (string) $milestoneId;
                        if (isset($milestoneProgress[$mpKey])) {
                            $mp = $milestoneProgress[$mpKey];
                            $achieved = $mp['achieved'] ?? false;
                            $rewardEarned = $mp['reward_earned'] ?? 0;
                            $achievedAt = $mp['achieved_at'] ?? null;
                        }
                    } else {
                        // Array format: [{"complete_ride_target": 1, ...}]
                        foreach ($milestoneProgress as $mp) {
                            if (is_array($mp)) {
                                $mpTarget = $mp['complete_ride_target'] ?? null;
                                if ($mpTarget == $milestoneTarget) {
                                    $achieved = $mp['achieved'] ?? false;
                                    $rewardEarned = $mp['reward_earned'] ?? 0;
                                    $achievedAt = $mp['achieved_at'] ?? null;
                                    break;
                                }
                            }
                        }
                    }
                }

                // Get the first achieved_at for the incentive
                if ($achieved && $achievedAt && !$incentiveAchievedAt) {
                    $incentiveAchievedAt = $achievedAt;
                }

                // Calculate remaining rides
                $remaining = max(0, $milestoneTarget - $currentCount);
                $statusText = $achieved ? 'Completed' : ($remaining > 0 ? "{$remaining} More Ride" . ($remaining > 1 ? 's' : '') . ' Left' : 'Completed');

                $formattedMilestones[] = [
                    'id' => (string) $milestoneId,
                    'target' => (string) $milestoneTarget,
                    'title' => "Complete {$milestoneTarget} Rides",
                    'status' => $achieved ? 'completed' : 'pending',
                    'status_text' => $statusText,
                    'reward' => (string) $milestoneReward,
                    'reward_display' => '+$' . number_format($milestoneReward, 1),
                    'achieved' => $achieved,
                    'reward_earned' => $rewardEarned,
                    'achieved_at' => $achievedAt,
                ];
            }
        }

        return [
            'id' => $incentive['id'] ?? null,
            'status' => $statusBadge,
            'title' => $incentive['title'] ?? '',
            'description' => $description ?: ($incentive['description'] ?? ''),
            'time_info' => $timeInfo,
            'achieved_at' => $incentiveAchievedAt,
            'time_label' => $timeLabel,
            'earned_amount' => $earnedAmount,
            'reward_amount' => $incentive['reward_amount'] ?? 0,
            'progress' => [
                'current' => $currentCount,
                'target' => $targetCount,
                'percentage' => $progressPercentage,
                'display' => "{$currentCount}/{$targetCount}",
            ],
            'milestones' => $formattedMilestones,
            'is_live' => $isLive,
            'is_completed' => $isCompleted,
            'is_upcoming' => $isUpcoming,
        ];
    }

    public function getByStatus($status): JsonResponse
    {
        try {
            $driverId = Auth::id();
            $status = strtolower($status);  // live, completed, upcoming

            $incentives = $this->incentiveService->getDriverIncentives($driverId, $status);

            // Format milestones_achieved to be an array instead of object
            $formattedIncentives = collect($incentives)->map(function ($incentive) {
                if (isset($incentive['progress']['milestones_achieved']) && is_array($incentive['progress']['milestones_achieved'])) {
                    $milestonesAchieved = $incentive['progress']['milestones_achieved'];

                    // Convert associative array (object) to indexed array with proper structure
                    if (!empty($milestonesAchieved)) {
                        $isAssoc = array_keys($milestonesAchieved) !== range(0, count($milestonesAchieved) - 1);
                        if ($isAssoc) {
                            // It's an associative array, convert to indexed array
                            $formatted = [];
                            $milestones = $incentive['milestones'] ?? [];

                            foreach ($milestonesAchieved as $milestoneId => $milestoneData) {
                                // Find the milestone definition to get target
                                $milestoneDef = collect($milestones)->first(function ($m) use ($milestoneId) {
                                    return ($m['id'] ?? $m['target'] ?? null) == $milestoneId;
                                });

                                $target = $milestoneDef['target'] ?? $milestoneId;
                                $formatted[] = [
                                    'complete_ride_target' => is_numeric($target) ? (int) $target : $target,
                                    'achieved' => $milestoneData['achieved'] ?? false,
                                    'reward_earned' => $milestoneData['reward_earned'] ?? null,
                                    'achieved_at' => $milestoneData['achieved_at'] ?? null,
                                ];
                            }

                            // Sort by target and convert to indexed array
                            usort($formatted, function ($a, $b) {
                                return ($a['complete_ride_target'] ?? 0) <=> ($b['complete_ride_target'] ?? 0);
                            });

                            $incentive['progress']['milestones_achieved'] = array_values($formatted);
                        }
                    }
                }
                return $incentive;
            })->toArray();

            return response()->json([
                'success' => true,
                'data' => $formattedIncentives,
                'message' => ucfirst($status) . ' incentives retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve incentives',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $driverId = Auth::id();
            $incentive = DriverIncentive::forDriver($driverId)
                ->find($id);

            if (!$incentive) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incentive not found'
                ], 404);
            }

            $progress = $incentive->calculateProgress($driverId);
            $guidelines = $this->incentiveService->getIncentiveGuidelines();
            $progressRecord = DriverIncentiveProgress::where('driver_id', $driverId)
                ->where('incentive_id', $id)
                ->first();

            $currentCount = $progress['current_count'] ?? 0;
            $targetCount = $progress['target_count'] ?? 0;
            $milestones = $incentive->milestones ?? [];
            $milestoneProgress = $progress['milestones_achieved'] ?? [];

            // Format milestones for UI (Image 2 format)
            $formattedMilestones = [];
            if (!empty($milestones)) {
                foreach ($milestones as $milestone) {
                    $milestoneTarget = $milestone['target'] ?? 0;
                    $milestoneId = $milestone['id'] ?? $milestoneTarget;
                    $milestoneReward = $milestone['reward'] ?? 0;

                    // Find milestone progress
                    $achieved = false;
                    $rewardEarned = 0;
                    $achievedAt = null;

                    foreach ($milestoneProgress as $mp) {
                        $mpTarget = is_array($mp) ? ($mp['complete_ride_target'] ?? null) : null;
                        if ($mpTarget == $milestoneTarget) {
                            $achieved = $mp['achieved'] ?? false;
                            $rewardEarned = $mp['reward_earned'] ?? 0;
                            $achievedAt = $mp['achieved_at'] ?? null;
                            break;
                        }
                    }

                    // Calculate remaining rides
                    $remaining = max(0, $milestoneTarget - $currentCount);
                    $statusText = $achieved ? 'Completed' : ($remaining > 0 ? "{$remaining} More Ride" . ($remaining > 1 ? 's' : '') . ' Left' : 'Completed');

                    $formattedMilestones[] = [
                        'id' => $milestoneId,
                        'target' => $milestoneTarget,
                        'title' => "Complete {$milestoneTarget} Rides",
                        'status' => $achieved ? 'completed' : 'pending',
                        'status_text' => $statusText,
                        'reward' => $milestoneReward,
                        'reward_display' => '+$' . number_format($milestoneReward, 1),
                        'achieved' => $achieved,
                        'reward_earned' => $rewardEarned,
                        'achieved_at' => $achievedAt,
                    ];
                }
            }

            // Format time info
            $timeInfo = '';
            if ($incentive->isLive()) {
                $endTime = Carbon::parse($incentive->end_time);
                if ($endTime->isToday()) {
                    $timeInfo = 'Ends Today at ' . $endTime->format('g:i A');
                } else {
                    $timeInfo = 'Ends ' . $endTime->format('M j') . ' at ' . $endTime->format('g:i A');
                }
            } elseif ($incentive->isUpcoming()) {
                $startTime = Carbon::parse($incentive->start_time);
                if ($startTime->isToday()) {
                    $timeInfo = 'Start Today at ' . $startTime->format('g:i A');
                } else {
                    $timeInfo = 'Starts ' . $startTime->format('M j') . ' at ' . $startTime->format('g:i A');
                }
            }

            // Format description
            $description = '';
            if ($incentive->isLive() && $targetCount > $currentCount) {
                $remaining = $targetCount - $currentCount;
                $description = "Complete {$remaining} More Rides and Get a \$" . number_format($incentive->reward_amount ?? 0, 2) . ' Bonus.';
            } elseif ($incentive->isUpcoming()) {
                $description = 'Get a $' . number_format($incentive->reward_amount ?? 0, 2) . ' Bonus when you hit your target.';
            } else {
                $description = $incentive->description ?? '';
            }

            $data = [
                'id' => $incentive->id,
                'status' => $incentive->isLive() ? 'live' : ($incentive->isUpcoming() ? 'upcoming' : ($incentive->isCompleted() ? 'completed' : $incentive->status)),
                'title' => $incentive->title ?? '',
                'description' => $description,
                'time_info' => $timeInfo,
                'reward_amount' => $incentive->reward_amount ?? 0,
                'progress' => [
                    'current' => $currentCount,
                    'target' => $targetCount,
                    'percentage' => $progress['progress_percentage'] ?? 0,
                    'display' => "{$currentCount}/{$targetCount}",
                ],
                'milestones' => $formattedMilestones,
                'guidelines' => $guidelines,
                'is_live' => $incentive->isLive(),
                'is_upcoming' => $incentive->isUpcoming(),
                'is_completed' => $incentive->isCompleted() || ($progress['is_completed'] ?? false),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Incentive details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve incentive details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getProgress($id): JsonResponse
    {
        try {
            $driverId = Auth::id();
            $incentive = DriverIncentive::forDriver($driverId)->find($id);

            if (!$incentive) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incentive not found'
                ], 404);
            }

            $progress = $incentive->calculateProgress($driverId);

            return response()->json([
                'success' => true,
                'data' => $progress,
                'message' => 'Incentive progress retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve incentive progress',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getGuidelines(): JsonResponse
    {
        try {
            $guidelines = $this->incentiveService->getIncentiveGuidelines();

            return response()->json([
                'success' => true,
                'data' => $guidelines,
                'message' => 'Incentive guidelines retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve guidelines',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDailySummary(Request $request): JsonResponse
    {
        try {
            $driverId = Auth::id();
            $date = $request->query('date', Carbon::today()->format('Y-m-d'));

            $summary = $this->incentiveService->getDriverIncentiveSummary($driverId, 'daily', $date);

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Daily incentive summary retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve daily summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getWeeklySummary(Request $request): JsonResponse
    {
        try {
            $driverId = Auth::id();
            $weekStart = $request->query('week_start', Carbon::now()->startOfWeek()->format('Y-m-d'));

            $summary = $this->incentiveService->getDriverIncentiveSummary($driverId, 'weekly', null, null, null, $weekStart);

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Weekly incentive summary retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve weekly summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getMonthlySummary(Request $request): JsonResponse
    {
        try {
            $driverId = Auth::id();
            $month = $request->query('month', Carbon::now()->format('Y-m'));

            $summary = $this->incentiveService->getDriverIncentiveSummary($driverId, 'monthly', null, $month);

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Monthly incentive summary retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve monthly summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getYearlySummary(Request $request): JsonResponse
    {
        try {
            $driverId = Auth::id();
            $year = $request->query('year', Carbon::now()->format('Y'));

            $summary = $this->incentiveService->getDriverIncentiveSummary($driverId, 'yearly', null, null, $year);

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Yearly incentive summary retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve yearly summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:upcoming,live,completed,expired,cancelled'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $incentive = DriverIncentive::find($id);
            if (!$incentive) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incentive not found'
                ], 404);
            }

            $incentive->status = $request->status;
            $incentive->save();

            return response()->json([
                'success' => true,
                'data' => $incentive,
                'message' => 'Incentive status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update incentive status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
