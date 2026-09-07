<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\DriverAttendance;
use App\Models\DriverRating;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DriverPerformanceController extends Controller
{
    public function getPerformanceOverview(Request $request): JsonResponse
    {
        try {
            $driver = Auth::user();
            $last20Orders = $request->input('last_20_orders', false);

            $allBookings = Booking::where('driver_id', $driver->id);

            // Calculate time online based on booking span (First started -> Last completed)
            // This matches the logic in PaymentController::getRideSummary
            $timeOnline = 0;

            $firstBooking = Booking::where('driver_id', $driver->id)
                ->whereNotNull('started_at')
                ->orderBy('started_at', 'asc')
                ->first();

            $lastBooking = Booking::where('driver_id', $driver->id)
                ->whereNotNull('completed_at')
                ->orderBy('completed_at', 'desc')
                ->first();

            if ($firstBooking && $lastBooking) {
                $start = Carbon::parse($firstBooking->started_at);
                $end = Carbon::parse($lastBooking->completed_at);
                $timeOnline = $start->diffInMinutes($end);
            }

            $hours = floor($timeOnline / 60);
            $minutes = $timeOnline % 60;
            $timeOnlineFormatted = sprintf('%d:%02d Hrs', $hours, $minutes);

            $totalTrips = $allBookings->count();
            $completedTrips = $allBookings->where('status', 'completed')->count();
            $completionRate = $totalTrips > 0 ? round(($completedTrips / $totalTrips) * 100) : 0;
            $avgRating = $allBookings->whereNotNull('user_rating')->avg('user_rating') ?? 0;

            $last20Bookings = $allBookings->latest()->take(20)->get();
            $last20Completed = $last20Bookings->where('status', 'completed')->count();
            $last20CompletionRate = $last20Bookings->count() > 0
                ? round(($last20Completed / $last20Bookings->count()) * 100)
                : 0;
            $last20AvgRating = $last20Bookings->whereNotNull('user_rating')->avg('user_rating') ?? 0;

            $performanceCategory = $this->getPerformanceCategory($last20CompletionRate);

            $recentReviews = $this->getRecentReviews($driver->id, 4);

            return response()->json([
                'success' => true,
                'message' => 'Performance data retrieved successfully',
                'data' => [
                    'all_time_performance' => [
                        'time_online_hrs' => $timeOnlineFormatted,
                        'total_rides' => (string) $totalTrips,
                        'completed_rides' => (string) $completedTrips,
                        'completion_rate' => (string) $completionRate,
                        'avg_rating' => (string) round($avgRating, 1),
                    ],
                    'last_20_orders' => [
                        'completed' => (string) $last20Completed,
                        'completion_rate' => (string) $last20CompletionRate,
                        'avg_rating' => (string) round($last20AvgRating, 1),
                        'performance_indicator' => $performanceCategory,
                    ],
                    'rider_reviews' => $recentReviews,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve performance data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPerformanceDetails(Request $request): JsonResponse
    {
        try {
            $driver = Auth::user();
            $period = $request->input('period', 'all');  // 'today', 'week', 'month', 'all'

            $query = Booking::where('driver_id', $driver->id);
            $attendanceQuery = DriverAttendance::where('driver_id', $driver->id);

            switch ($period) {
                case 'today':
                    $query->whereDate('created_at', Carbon::today()->toDateString());
                    $attendanceQuery->whereDate('date', Carbon::today()->toDateString());
                    break;
                case 'week':
                    $query->whereBetween('created_at', [Carbon::now()->startOfWeek()->toDateTimeString(), Carbon::now()->endOfWeek()->toDateTimeString()]);
                    $attendanceQuery->whereBetween('date', [Carbon::now()->startOfWeek()->toDateString(), Carbon::now()->endOfWeek()->toDateString()]);
                    break;
                case 'month':
                    $query
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year);
                    $attendanceQuery
                        ->whereMonth('date', Carbon::now()->month)
                        ->whereYear('date', Carbon::now()->year);
                    break;
            }

            $bookings = $query->get();
            $totalOnlineHours = $attendanceQuery->sum('total_online_hours');

            $stats = [
                'total_trips' => $bookings->count(),
                'completed_trips' => $bookings->where('status', 'completed')->count(),
                'cancelled_trips' => $bookings->where('status', 'cancelled')->count(),
                'completion_rate' => $bookings->count() > 0
                    ? round(($bookings->where('status', 'completed')->count() / $bookings->count()) * 100, 1)
                    : 0,
                'average_rating' => round($bookings->whereNotNull('user_rating')->avg('user_rating') ?? 0, 1),
                'total_earnings' => round($bookings->where('status', 'completed')->sum('driver_amount'), 2),
                'time_online_hours' => $this->formatHours($totalOnlineHours),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Performance details retrieved successfully',
                'data' => [
                    'period' => $period,
                    'statistics' => $stats,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve performance details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAllReviews(Request $request): JsonResponse
    {
        try {
            $driver = Auth::user();
            $perPage = $request->input('per_page', 20);
            $page = $request->input('page', 1);

            $bookings = Booking::where('driver_id', $driver->id)
                ->where('status', 'completed')
                ->whereNotNull('user_rating')
                ->with(['user'])
                ->latest('completed_at')
                ->paginate($perPage);

            $reviews = $bookings->map(function ($booking) {
                return $this->formatReview($booking);
            });

            return response()->json([
                'success' => true,
                'message' => 'Reviews retrieved successfully',
                'data' => [
                    'reviews' => $reviews,
                    'pagination' => [
                        'current_page' => (string) $bookings->currentPage(),
                        'last_page' => (string) $bookings->lastPage(),
                        'per_page' => (string) $bookings->perPage(),
                        'total' => (string) $bookings->total(),
                        'has_more_pages' => $bookings->hasMorePages() ? '1' : '0',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve reviews',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getRecentReviews(int $driverId, int $limit = 4): array
    {
        $bookings = Booking::where('driver_id', $driverId)
            ->where('status', 'completed')
            ->whereNotNull('user_rating')
            ->with(['user'])
            ->latest('completed_at')
            ->take($limit)
            ->get();

        return $bookings->map(function ($booking) {
            return $this->formatReview($booking);
        })->toArray();
    }

    private function formatReview($booking): array
    {
        $tags = [];
        if ($booking->meta_data && isset($booking->meta_data['rating_tags'])) {
            $tags = $booking->meta_data['rating_tags'];
        }

        $tagMap = [
            'clean_vehicle' => 'Clean',
            'friendly_driver' => 'Friendly',
            'safe_driver' => 'Safe',
            'good_navigation' => 'On Time',
            'professional' => 'Professional',
            'polite' => 'Polite',
            'on_time' => 'On Time',
        ];

        $displayTags = array_map(function ($tag) use ($tagMap) {
            return $tagMap[$tag] ?? ucfirst(str_replace('_', ' ', $tag));
        }, array_slice($tags, 0, 2));

        return [
            'rider' => [
                'id' => (string) ($booking->user->id ?? ''),
                'name' => $booking->user->name ?? 'Anonymous',
                'profile_photo' => $this->getProfilePhotoUrl($booking->user->profile_photo ?? null),
            ],
            'rating' => (string) ($booking->user_rating ?? '0'),
            'review_text' => $booking->driver_comment ?? '',
            'tags' => $displayTags,
            'reviewed_at' => $booking->completed_at ? $booking->completed_at->diffForHumans() : '',
            'reviewed_at_iso' => $booking->completed_at ? $booking->completed_at->toISOString() : '',
        ];
    }

    private function getPerformanceCategory(int $completionRate): array
    {
        if ($completionRate >= 80) {
            return [
                'category' => 'good',
                'label' => 'Good',
                'range' => '16-20',
                'color' => 'orange',
            ];
        } elseif ($completionRate >= 50) {
            return [
                'category' => 'average',
                'label' => 'Average',
                'range' => '10-15',
                'color' => 'gray',
            ];
        } else {
            return [
                'category' => 'bad',
                'label' => 'Bad',
                'range' => '0-9',
                'color' => 'gray',
            ];
        }
    }

    private function formatHours(float $hours): string
    {
        if ($hours < 1) {
            return round($hours * 60) . ' Min';
        }

        $wholeHours = floor($hours);
        $minutes = round(($hours - $wholeHours) * 60);

        if ($minutes == 0) {
            return $wholeHours . ':00 Hrs';
        }

        return $wholeHours . ':' . str_pad($minutes, 2, '0', STR_PAD_LEFT) . ' Hrs';
    }

    private function getProfilePhotoUrl(?string $profilePhoto): string
    {
        if (empty($profilePhoto)) {
            return '';
        }

        if (filter_var($profilePhoto, FILTER_VALIDATE_URL)) {
            return $profilePhoto;
        }

        return url('storage/' . $profilePhoto);
    }
}
