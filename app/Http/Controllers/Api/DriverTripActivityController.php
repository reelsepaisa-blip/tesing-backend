<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverTripActivityController extends Controller
{
    public function getTripActivity(Request $request): JsonResponse
    {
        try {
            $driver = $request->user();
            if ($driver->role_id !== '2' && $driver->role_id !== 2) {  // Check if user is a driver
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Driver role required.'
                ], 403);
            }

            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);
            $status = $request->get('status');  // completed, cancelled, all
            $tripType = $request->get('trip_type');  // ride_type_id
            $paymentMode = $request->get('payment_mode');  // cash, online, wallet
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');
            $period = $request->get('period', 'today');  // today, weekly, monthly, custom
            $distanceMin = $request->get('distance_min');
            $distanceMax = $request->get('distance_max');
            $amountMin = $request->get('amount_min');
            $amountMax = $request->get('amount_max');
            $cancelledBy = $request->get('cancelled_by');  // driver, user

            $query = Booking::where('driver_id', $driver->id)
                ->whereNotIn('status', ['pending', 'searching'])
                ->with([
                    'user:id,name,phone,profile_photo',
                    'rideType:id,name,base_price,price_per_km,price_per_minute',
                    'pickupZone' => function ($query) {
                        $query->select('id', 'name', 'city_id')->setEagerLoads([]);
                    },
                    'dropoffZone' => function ($query) {
                        $query->select('id', 'name', 'city_id')->setEagerLoads([]);
                    }
                ])
                ->orderBy('created_at', 'desc');

            if ($status && $status !== 'all') {
                if ($status === 'completed') {
                    $query->where('status', 'completed');
                } elseif ($status === 'cancelled') {
                    $query->where('status', 'cancelled');
                }
            }

            if ($tripType) {
                $query->where('ride_type_id', $tripType);
            }

            if ($paymentMode) {
                $query->where('payment_method', $paymentMode);
            }

            if ($distanceMin) {
                $query->where('actual_distance', '>=', $distanceMin);
            }
            if ($distanceMax) {
                $query->where('actual_distance', '<=', $distanceMax);
            }

            if ($amountMin) {
                $query->where('total_amount', '>=', $amountMin);
            }
            if ($amountMax) {
                $query->where('total_amount', '<=', $amountMax);
            }

            if ($cancelledBy) {
                if ($cancelledBy === 'user' || $cancelledBy === 'rider') {
                    $query
                        ->whereColumn('cancelled_by_id', '=', 'user_id')
                        ->where('status', 'cancelled');
                } elseif ($cancelledBy === 'driver') {
                    $query
                        ->whereColumn('cancelled_by_id', '=', 'driver_id')
                        ->where('status', 'cancelled');
                }
            }

            if ($dateFrom && $dateTo) {
                $query->whereBetween('created_at', [
                    Carbon::parse($dateFrom)->startOfDay(),
                    Carbon::parse($dateTo)->endOfDay()
                ]);
            } elseif ($period === 'today') {
                $query->whereDate('created_at', Carbon::today());
            } elseif ($period === 'weekly') {
                $query->whereBetween('created_at', [
                    Carbon::now()->startOfWeek(),
                    Carbon::now()->endOfWeek()
                ]);
            } elseif ($period === 'monthly') {
                $query
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year);
            }

            $trips = $query->paginate($perPage, ['*'], 'page', $page);

            $transformedTrips = $trips->getCollection()->map(function ($trip) {
                return $this->transformTripData($trip);
            });

            $summary = $this->getSummaryStatistics($driver->id, $period, $dateFrom, $dateTo);

            return response()->json([
                'success' => true,
                'data' => [
                    'trips' => $transformedTrips,
                    'pagination' => [
                        'current_page' => $trips->currentPage(),
                        'per_page' => $trips->perPage(),
                        'total' => $trips->total(),
                        'last_page' => $trips->lastPage(),
                        'has_more' => $trips->hasMorePages()
                    ],
                    'summary' => $summary,
                    'filters' => [
                        'status' => $status ?? '',
                        'trip_type' => $tripType ?? '',
                        'payment_mode' => $paymentMode ?? '',
                        'period' => $period ?? '',
                        'date_from' => $dateFrom ?? '',
                        'date_to' => $dateTo ?? '',
                        'distance_min' => $distanceMin ?? '',
                        'distance_max' => $distanceMax ?? '',
                        'amount_min' => $amountMin ?? '',
                        'amount_max' => $amountMax ?? '',
                        'cancelled_by' => ($cancelledBy === 'user' ? 'rider' : $cancelledBy) ?? ''
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trip activity',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSummary(Request $request): JsonResponse
    {
        try {
            $driver = $request->user();

            if ($driver->role_id !== '2' && $driver->role_id !== 2) {  // Check if user is a driver
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Driver role required.'
                ], 403);
            }

            $period = $request->get('period', 'today');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            $summary = $this->getSummaryStatistics($driver->id, $period, $dateFrom, $dateTo);

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getTripDetails(Request $request, $bookingId): JsonResponse
    {
        try {
            $driver = $request->user();

            if ((int)$driver->role_id !== 2) {  // Check if user is a driver
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Driver role required.'
                ], 403);
            }

            $trip = Booking::where('id', $bookingId)
                ->where('driver_id', $driver->id)
                ->with([
                    'user:id,name,phone,profile_photo',
                    'rideType:id,name,base_price,price_per_km,price_per_minute',
                    'pickupZone' => function ($query) {
                        $query->select('id', 'name', 'city_id')->setEagerLoads([]);
                    },
                    'dropoffZone' => function ($query) {
                        $query->select('id', 'name', 'city_id')->setEagerLoads([]);
                    }
                ])
                ->first();

            if (!$trip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Trip not found'
                ], 404);
            }

            $tripData = $this->transformTripData($trip, true);

            return response()->json([
                'success' => true,
                'data' => $tripData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trip details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function transformTripData($trip, $includeDetails = false)
    {
        $cancelledBy = '';
        if ($trip->status === 'cancelled' && $trip->cancelled_by_id) {
            if ($trip->cancelled_by_id == $trip->driver_id) {
                $cancelledBy = 'by driver';
            } elseif ($trip->cancelled_by_id == $trip->user_id) {
                $cancelledBy = 'by rider';
            }
        }

        $statusLabel = $this->getStatusLabel($trip->status);
        if ($trip->status === 'cancelled' && $cancelledBy) {
            $statusLabel .= ' ' . $cancelledBy;
        }

        $data = [
            'id' => $trip->id,
            'booking_code' => $trip->booking_code,
            'status' => $trip->status,
            'status_label' => $statusLabel,
            'created_at' => $trip->created_at->format('Y-m-d H:i:s'),
            'created_at_formatted' => $trip->created_at->format('D, j M • g:i A'),
            'pickup_address' => $trip->pickup_address,
            'dropoff_address' => $trip->dropoff_address,
            'payment_method' => $trip->payment_method ?? '',
            'payment_method_label' => $this->getPaymentMethodLabel($trip->payment_method),
            'ride_type' => [
                'id' => $trip->rideType->id ?? '',
                'name' => $trip->rideType->name ?? ''
            ],
            'user' => [
                'id' => $trip->user->id ?? '',
                'name' => $trip->user->name ?? 'Unknown',
                'phone' => $trip->user->phone ?? '',
                'rating' => $trip->user_rating ?? 0,
                'comment' => $trip->user_comment ?? '',
                'profile_photo' => $this->getProfilePhotoUrl($trip->user->profile_photo ?? '')
            ]
        ];

        if ($trip->status === 'completed') {
            // Use driver_amount from bookings table instead of calculating
            $driverAmount = (float) ($trip->driver_amount ?? 0);

            $data['financial'] = [
                'total_fare' => $trip->total_amount ?? 0,
                'distance' => number_format($trip->actual_distance ?? $trip->distance ?? 0, 2),
                'duration' => $this->formatDuration($trip->actual_duration ?? $trip->estimated_duration ?? 0),
                'fare_breakdown' => [
                    'base_fare' => number_format($trip->base_fare ?? 0, 2),
                    'distance_fare' => number_format($trip->distance_fare ?? 0, 2),
                    'time_fare' => number_format($trip->time_fare ?? 0, 2),
                    'waiting_charge' => number_format($trip->waiting_charge ?? 0, 2),
                    'night_charge' => number_format($trip->night_charge ?? 0, 2),
                    'surge_amount' => number_format($trip->surge_amount ?? 0, 2),
                    'offer_discount_amount' => number_format(- ($trip->discount_amount ?? 0), 2),
                    'subtotal' => number_format($trip->subtotal ?? 0, 2),
                    'tax_amount' => number_format($trip->tax_amount ?? 0, 2),
                    'total_amount' => number_format(
                        ($trip->total_amount ?? 0) - ($trip->debt_amount ?? 0),
                        2
                    ),
                    'tip_amount' => number_format($trip->tip_amount ?? 0, 2),
                    'driver_amount' => number_format($driverAmount, 2),
                    'debt_amount' => number_format($trip->debt_amount ?? 0, 2),
                    'cancel_charge' => number_format($trip->cancellation_charge ?? 0, 2),
                ]
            ];
        } elseif ($trip->status === 'cancelled') {
            $data['cancellation'] = [
                'reason' => $trip->cancellation_reason ?? 'No reason provided',
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => $trip->cancelled_at ? $trip->cancelled_at->format('Y-m-d H:i:s') : '',
                'cancellation_charge' => number_format($trip->cancellation_charge ?? 0, 2)
            ];

            $data['financial'] = [
                'total_fare' => $trip->total_amount ?? 0,
                'driver_amount' => $trip->driver_amount ?? 0,
                'distance' => number_format($trip->distance ?? $trip->estimated_distance ?? 0, 2),
                'duration' => $this->formatDuration($trip->actual_duration ?? $trip->estimated_duration ?? 0),
                'fare_breakdown' => [
                    'base_fare' => number_format($trip->base_fare ?? 0, 2),
                    'distance_fare' => number_format($trip->distance_fare ?? 0, 2),
                    'time_fare' => number_format($trip->time_fare ?? 0, 2),
                    'waiting_charge' => number_format($trip->waiting_charge ?? 0, 2),
                    'night_charge' => number_format($trip->night_charge ?? 0, 2),
                    'surge_amount' => number_format($trip->surge_amount ?? 0, 2),
                    'offer_discount_amount' => number_format(- ($trip->discount_amount ?? 0), 2),
                    'subtotal' => number_format($trip->subtotal ?? 0, 2),
                    'tax_amount' => number_format($trip->tax_amount ?? 0, 2),
                    'tip_amount' => number_format($trip->tip_amount ?? 0, 2),
                    'total_amount' => number_format($trip->total_amount ?? 0, 2),
                    'cancel_charge' => number_format($trip->cancellation_charge ?? 0, 2)
                ]
            ];
        }

        $data['timestamps'] = [
            'scheduled_at' => $trip->scheduled_at ? $trip->scheduled_at->format('Y-m-d H:i:s') : '',
            'started_at' => $trip->started_at ? $trip->started_at->format('Y-m-d H:i:s') : '',
            'completed_at' => $trip->completed_at ? $trip->completed_at->format('Y-m-d H:i:s') : '',
            'cancelled_at' => $trip->cancelled_at ? $trip->cancelled_at->format('Y-m-d H:i:s') : ''
        ];

        if ($includeDetails) {
            $data['details'] = [
                'pickup_coordinates' => [
                    'latitude' => $trip->pickup_latitude ?? '',
                    'longitude' => $trip->pickup_longitude ?? ''
                ],
                'dropoff_coordinates' => [
                    'latitude' => $trip->dropoff_latitude ?? '',
                    'longitude' => $trip->dropoff_longitude ?? ''
                ],
                'zones' => [
                    'pickup_zone' => $trip->pickupZone ? [
                        'id' => $trip->pickupZone->id,
                        'name' => $trip->pickupZone->name
                    ] : '',
                    'dropoff_zone' => $trip->dropoffZone ? [
                        'id' => $trip->dropoffZone->id,
                        'name' => $trip->dropoffZone->name
                    ] : ''
                ],
                'ratings' => [
                    'user_rating' => $trip->user_rating ?? '',
                    'user_review' => $trip->user_review ?? '',
                    'user_comment' => $trip->user_comment ?? '',
                    'driver_rating' => $trip->driver_rating ?? '',
                    'driver_review' => $trip->driver_review ?? '',
                    'driver_comment' => $trip->driver_comment ?? ''
                ],
                'waiting_time' => $trip->waiting_time ?? '',
                'trip_code' => $trip->trip_code ?? '',
                'otp' => $trip->otp ?? ''
            ];
        }

        return $data;
    }

    private function getSummaryStatistics($driverId, $period = 'today', $dateFrom = null, $dateTo = null)
    {
        $query = Booking::where('driver_id', $driverId);

        if ($dateFrom && $dateTo) {
            $query->whereBetween('created_at', [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay()
            ]);
        } elseif ($period === 'today') {
            $query->whereDate('created_at', Carbon::today());
        } elseif ($period === 'weekly') {
            $query->whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ]);
        } elseif ($period === 'monthly') {
            $query
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year);
        }

        $stats = $query->selectRaw('
            COUNT(*) as total_trips,
            COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_trips,
            COUNT(CASE WHEN status = "cancelled" THEN 1 END) as cancelled_trips,
            SUM(CASE WHEN status = "completed" THEN driver_amount + COALESCE(tip_amount, 0) ELSE 0 END) as total_earnings,
            SUM(CASE WHEN status = "completed" THEN actual_distance ELSE 0 END) as total_distance,
            AVG(CASE WHEN status = "completed" THEN driver_rating END) as avg_rating
        ')->first();

        $startDate = null;
        $endDate = null;

        if ($dateFrom && $dateTo) {
            $startDate = Carbon::parse($dateFrom)->startOfDay();
            $endDate = Carbon::parse($dateTo)->endOfDay();
        } elseif ($period === 'today') {
            $startDate = Carbon::today()->startOfDay();
            $endDate = Carbon::today()->endOfDay();
        } elseif ($period === 'weekly') {
            $startDate = Carbon::now()->startOfWeek();
            $endDate = Carbon::now()->endOfWeek();
        } elseif ($period === 'monthly') {
            $startDate = Carbon::now()->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();
        }

        if (!$startDate || !$endDate) {
            $startDate = Carbon::today()->startOfDay();
            $endDate = Carbon::today()->endOfDay();
        }

        $attendanceRecords = DB::table('driver_attendance')
            ->where('driver_id', $driverId)
            ->where('online_time', '<', $endDate)
            ->where(function ($query) use ($startDate) {
                $query->where(function ($q) use ($startDate) {
                    $q
                        ->whereNotNull('offline_time')
                        ->where('offline_time', '>', $startDate);
                })->orWhereNull('offline_time');  // Still online (no offline_time)
            })
            ->get();

        $totalOnlineHours = 0;
        $currentTime = Carbon::now();

        foreach ($attendanceRecords as $record) {
            $onlineTime = Carbon::parse($record->online_time);
            $offlineTime = $record->offline_time ? Carbon::parse($record->offline_time) : $currentTime;

            $sessionStart = $onlineTime->gt($startDate) ? $onlineTime : $startDate;
            $sessionEnd = $offlineTime->lt($endDate) ? $offlineTime : $endDate;

            if ($sessionStart->lte($sessionEnd)) {
                $seconds = $sessionStart->diffInSeconds($sessionEnd);
                $hours = $seconds / 3600;
                $totalOnlineHours += $hours;
            }
        }

        return [
            'total_trips' => (string)($stats->total_trips ?? 0),
            'completed_trips' => (string)$stats->completed_trips ?? 0,
            'cancelled_trips' => (string)$stats->cancelled_trips ?? 0,
            'total_earnings' => number_format($stats->total_earnings ?? 0, 2),
            'total_distance' => number_format($stats->total_distance ?? 0, 2),
            'total_online_hours' => number_format($totalOnlineHours, 2),
            'average_rating' => number_format($stats->avg_rating ?? 0, 1),
            'completion_rate' => $stats->total_trips > 0
                ? number_format(($stats->completed_trips / $stats->total_trips) * 100, 1)
                : 0
        ];
    }

    private function getProfilePhotoUrl($profilePhoto)
    {
        if (!$profilePhoto) {
            return '';
        }

        if (filter_var($profilePhoto, FILTER_VALIDATE_URL)) {
            return $profilePhoto;
        }

        return url('storage/' . $profilePhoto);
    }

    private function getStatusLabel($status)
    {
        $labels = [
            'pending' => 'Pending',
            'searching' => 'Searching',
            'accepted' => 'Accepted',
            'arrived' => 'Arrived',
            'started' => 'Started',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'expired' => 'Expired'
        ];

        return $labels[$status] ?? ucfirst($status);
    }

    private function getPaymentMethodLabel($paymentMethod)
    {
        $labels = [
            'cash' => 'Cash',
            'online' => 'Online',
            'wallet' => 'Wallet',
            'split' => 'Split Payment'
        ];

        return $labels[$paymentMethod] ?? ucfirst($paymentMethod);
    }

    private function formatDuration($minutes)
    {
        if ($minutes < 60) {
            return $minutes . ' Min';
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return $hours . ' Hr' . ($hours > 1 ? 's' : '');
        }

        return $hours . ' Hr ' . $remainingMinutes . ' Min';
    }
}
