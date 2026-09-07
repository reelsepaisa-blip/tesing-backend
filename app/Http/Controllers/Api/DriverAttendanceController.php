<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverAttendance;
use App\Models\DriverLocation;
use App\Models\User;
use App\Models\Booking;
use App\Models\Zone;
use App\Services\GeocodingService;
use App\Services\DriverAutoLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Carbon\Carbon;

class DriverAttendanceController extends Controller
{
    protected $driverAutoLocationService;

    public function __construct(DriverAutoLocationService $driverAutoLocationService)
    {
        $this->driverAutoLocationService = $driverAutoLocationService;
    }


    public function attendanceStatus(Request $request)
    {
        try {
            $user = Auth::user();


            if ($user->role_id != 2) {
                
                return response()->json([
                    'success' => false,
                    'message' => 'Only drivers can change online status'
                ], 403);
            }

            $request->validate([
                'online' => 'required|in:0,1',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
            ]);

            $wantsOnline = $request->input('online') == 1;


            // Check if driver can go online before proceeding
            if ($wantsOnline && !$user->canGoOnline()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot go online. Please complete profile and document verification.',
                    'data' => [
                        'can_go_online' => '0',
                        'is_verified' => $user->is_verified ? '1' : '0'
                    ]
                ], 422);
            }

            $metaData = [
                'device_info' => $request->input('device_info'),
                'app_version' => $request->input('app_version'),
                'location' => [
                    'latitude' => $request->input('latitude'),
                    'longitude' => $request->input('longitude')
                ]
            ];

            $session = null;

            if ($wantsOnline) {
                // User wants to go online (online = 1)
                

                // Reactivate or clean up inactive DriverLocation records
                // When a driver is automatically marked offline, their location record becomes inactive
                // We need to reactivate or create a new one when they go back online
                $inactiveLocations = DriverLocation::where('driver_id', $user->id)
                    ->where('is_active', false)
                    ->get();

                if ($inactiveLocations->isNotEmpty()) {
                    // Set all inactive locations to active=false (already false, but ensure consistency)
                    // We'll create a new active one when location data is provided
                    foreach ($inactiveLocations as $inactiveLocation) {
                        $inactiveLocation->update(['is_active' => false]);
                    }
                }

                // Use direct DB update to ensure it persists
                $updateResult = DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'is_online' => 1,
                        'last_location_at' => now(),
                        'last_latitude' => $request->input('latitude'),
                        'last_longitude' => $request->input('longitude'),
                        'updated_at' => now()
                    ]);

                // Refresh user to get updated values
                $user->refresh();


                // Check if there's already an active session
                // If driver was automatically marked offline, there might be a stale session
                // that needs to be properly handled
                $existingSession = DriverAttendance::getCurrentOnlineSession($user->id);


                if (!$existingSession) {
                    // Create new attendance session only if one doesn't exist
                    $session = DriverAttendance::create([
                        'driver_id' => $user->id,
                        'online_time' => now(),
                        'date' => now()->toDateString(),
                        'meta_data' => $metaData,
                    ]);
                    
                } else {
                    // Use existing active session
                    // If somehow there's a session that's marked offline but still considered "active",
                    // we need to handle it properly
                    if (!$existingSession->isActive()) {
                        // Session exists but is marked offline, create a new one
                        
                        $session = DriverAttendance::create([
                            'driver_id' => $user->id,
                            'online_time' => now(),
                            'date' => now()->toDateString(),
                            'meta_data' => $metaData,
                        ]);
                        
                    } else {
                        $session = $existingSession;
                        
                    }
                }

                $this->driverAutoLocationService->startAutoLocationUpdates($user);

                if ($request->input('latitude') && $request->input('longitude')) {
                    $initialLocationData = [
                        'latitude' => $request->input('latitude'),
                        'longitude' => $request->input('longitude'),
                        'heading' => $request->input('heading', 0),
                        'speed' => $request->input('speed', 0),
                        'accuracy' => $request->input('accuracy', 10),
                        'battery_level' => $request->input('battery_level'),
                        'is_charging' => $request->input('is_charging', false),
                    ];

                    $this->driverAutoLocationService->updateDriverLocation($user, $initialLocationData);
                }

                $message = 'You are now online and ready to receive ride requests';
                $status = 'online';
            } else {
                // User wants to go offline (online = 0)
                

                $session = DriverAttendance::getCurrentOnlineSession($user->id);
                if ($session) {
                    $session->markOffline();
                    
                }

                // Use direct DB update to ensure it persists
                $updateResult = DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'is_online' => 0,
                        'updated_at' => now()
                    ]);

                // Refresh user to get updated values
                $user->refresh();


                $this->driverAutoLocationService->stopAutoLocationUpdates($user);

                $message = 'You are now offline';
                $status = 'offline';
            }

            // Refresh user one more time before returning response
            $user->refresh();


            $dashboard = $this->getDashboardData($user);

            $sessionData = null;
            if ($session) {
                $sessionData = [
                    'id' => (string) $session->id,
                    'driver_id' => (string) $session->driver_id,
                    'online_time' => $session->online_time ? $session->online_time->format('Y-m-d H:i:s') : '',
                    'offline_time' => $session->offline_time ? $session->offline_time->format('Y-m-d H:i:s') : '',
                    'total_online_seconds' => $session->total_online_seconds ? (string) $session->total_online_seconds : '',
                    'total_online_hours' => $session->total_online_hours ? (string) $session->total_online_hours : '',
                    'date' => $session->date ? $session->date->format('Y-m-d') : '',
                    'duration_formatted' => $session->getFormattedDuration(),
                    'created_at' => $session->created_at ? $session->created_at->format('Y-m-d H:i:s') : '',
                    'updated_at' => $session->updated_at ? $session->updated_at->format('Y-m-d H:i:s') : '',
                ];
            }

            $responseData = [
                'success' => true,
                'message' => $message,
                'data' => [
                    'status' => $status,
                    'is_online' => $user->is_online ? '1' : '0',
                    'session' => $sessionData,
                    'dashboard' => $dashboard
                ]
            ];

            

            return response()->json($responseData);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle online status',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getDashboard(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->isDriver()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only drivers can access dashboard'
                ], 403);
            }

            $dashboard = $this->getDashboardData($user);

            return response()->json([
                'success' => true,
                'data' => $dashboard
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getAttendanceHistory(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->isDriver()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only drivers can access attendance history'
                ], 403);
            }

            $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
            $endDate = $request->input('end_date', now()->toDateString());
            $limit = $request->input('limit', 30);

            $attendance = DriverAttendance::forDriver($user->id)
                ->dateRange($startDate, $endDate)
                ->orderBy('date', 'desc')
                ->orderBy('online_time', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($record) {
                    return [
                        'id' => (string) $record->id,
                        'date' => $record->date->format('Y-m-d'),
                        'online_time' => $record->online_time->format('H:i:s'),
                        'offline_time' => $record->offline_time ? $record->offline_time->format('H:i:s') : '',
                        'total_hours' => (string) ($record->total_online_hours ?: $record->getOnlineHours()),
                        'duration_formatted' => $record->getFormattedDuration(),
                        'is_active' => $record->isActive() ? '1' : '0',
                        'meta_data' => $record->meta_data ? json_encode($record->meta_data) : ''
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'attendance' => $attendance,
                    'summary' => [
                        'total_records' => (string) $attendance->count(),
                        'total_hours' => (string) $attendance->sum('total_hours'),
                        'avg_hours_per_day' => (string) ($attendance->count() > 0 ? round($attendance->sum('total_hours') / $attendance->count(), 2) : 0)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get attendance history',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function getDashboardData($driver)
    {
        $today = now()->toDateString();
        $currentSession = DriverAttendance::getCurrentOnlineSession($driver->id);

        $todayOnlineHours = DriverAttendance::getTodayTotalHours($driver->id);
        if ($currentSession) {
            $todayOnlineHours += $currentSession->getOnlineHours();
        }

        $todayBookings = Booking::where('driver_id', $driver->id)
            ->whereDate('created_at', $today)
            ->get();

        $totalTodayRides = $todayBookings->count();
        $completedTodayRides = $todayBookings->where('status', 'completed')->count();
        $completionRate = $totalTodayRides > 0 ? round(($completedTodayRides / $totalTodayRides) * 100, 1) : 0;

        $avgRating = $driver->bookingsAsDriver()
            ->whereNotNull('user_rating')
            ->avg('user_rating') ?: 0;

        $recentTrip = $driver->bookingsAsDriver()
            ->whereIn('status', ['completed', 'cancelled'])
            ->with(['user', 'pickupZone'])
            ->latest('completed_at')
            ->first();

        $hotspotAreas = $this->getHotspotAreas($driver->id);

        $todayEarnings = $todayBookings->where('status', 'completed')->sum('driver_amount');

        return [
            'online_status' => [
                'is_online' => $driver->is_online ? '1' : '0',
                'current_session' => $currentSession ? [
                    'started_at' => $currentSession->online_time->format('H:i'),
                    'duration' => $currentSession->getFormattedDuration(),
                    'hours' => (string) $currentSession->getOnlineHours()
                ] : [
                    'started_at' => '',
                    'duration' => '',
                    'hours' => ''
                ],
                'today_total_hours' => (string) round($todayOnlineHours, 1),
                'today_total_formatted' => $this->formatHours($todayOnlineHours)
            ],
            'rides' => [
                'today_total' => (string) $totalTodayRides,
                'today_completed' => (string) $completedTodayRides,
                'completion_rate' => (string) $completionRate,
                'completion_rate_formatted' => $completionRate . '%'
            ],
            'rating' => [
                'average' => (string) round($avgRating, 1),
                'total_ratings' => (string) $driver->bookingsAsDriver()->whereNotNull('user_rating')->count()
            ],
            'earnings' => [
                'today' => (string) round($todayEarnings, 2),
                'today_formatted' => '₹' . number_format($todayEarnings, 2)
            ],
            'recent_trip' => $recentTrip ? [
                'id' => (string) $recentTrip->id,
                'booking_code' => $recentTrip->booking_code ?? '',
                'customer_name' => $recentTrip->user->name ?? '',
                'pickup_address' => $recentTrip->pickup_address ?? '',
                'dropoff_address' => $recentTrip->dropoff_address ?? '',
                'amount' => '₹' . number_format($recentTrip->driver_amount ?? 0, 2),
                'status' => $recentTrip->status ?? '',
                'completed_at' => $recentTrip->completed_at ? $recentTrip->completed_at->format('H:i A') : '',
                'distance' => $recentTrip->actual_distance ? $recentTrip->actual_distance . ' km' : '',
                'rating' => $recentTrip->user_rating ? (string) $recentTrip->user_rating : ''
            ] : [
                'id' => '',
                'booking_code' => '',
                'customer_name' => '',
                'pickup_address' => '',
                'dropoff_address' => '',
                'amount' => '',
                'status' => '',
                'completed_at' => '',
                'distance' => '',
                'rating' => ''
            ],
            'hotspot_areas' => $hotspotAreas
        ];
    }


    private function getHotspotAreas($driverId, $limit = 5)
    {
        return GeocodingService::getDriverHotspots($driverId, $limit);
    }


    private function formatHours($hours)
    {
        $totalMinutes = $hours * 60;
        $h = floor($totalMinutes / 60);
        $m = $totalMinutes % 60;

        if ($h > 0) {
            return "{$h}h {$m}m";
        }

        return "{$m}m";
    }


    public function getOnlineStatus(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->isDriver()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only drivers can check online status'
                ], 403);
            }

            $currentSession = DriverAttendance::getCurrentOnlineSession($user->id);
            $todayOnlineHours = DriverAttendance::getTodayTotalHours($user->id);

            if ($currentSession) {
                $todayOnlineHours += $currentSession->getOnlineHours();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'is_online' => $user->is_online ? '1' : '0',
                    'can_go_online' => $user->canGoOnline() ? '1' : '0',
                    'current_session' => $currentSession ? [
                        'id' => (string) $currentSession->id,
                        'started_at' => $currentSession->online_time->format('Y-m-d H:i:s'),
                        'duration_seconds' => (string) $currentSession->calculateOnlineTime(),
                        'duration_formatted' => $currentSession->getFormattedDuration()
                    ] : [
                        'id' => '',
                        'started_at' => '',
                        'duration_seconds' => '',
                        'duration_formatted' => ''
                    ],
                    'today_total_hours' => (string) round($todayOnlineHours, 2),
                    'today_total_formatted' => $this->formatHours($todayOnlineHours)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get online status',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getVerificationStatus(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->isDriver()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only drivers can check verification status'
                ], 403);
            }

            $isVerified = $user->updateVerificationStatus();
            $documentSummary = $user->getDocumentVerificationSummary();

            return response()->json([
                'success' => true,
                'data' => [
                    'is_verified' => $user->is_verified ? '1' : '0',
                    'verified_at' => $user->verified_at ? $user->verified_at->format('Y-m-d H:i:s') : '',
                    'can_go_online' => $user->canGoOnline() ? '1' : '0',
                    'document_summary' => [
                        'total' => (string) $documentSummary['total'],
                        'approved' => (string) $documentSummary['approved'],
                        'pending' => (string) $documentSummary['pending'],
                        'rejected' => (string) $documentSummary['rejected'],
                        'is_complete' => $documentSummary['is_complete'] ? '1' : '0',
                        'missing_documents' => implode(', ', $documentSummary['missing_documents'])
                    ],
                    'requirements' => [
                        'profile_verified' => $user->driverProfile ? '1' : '0',
                        'documents_verified' => $documentSummary['is_complete'] ? '1' : '0',
                        'vehicle_active' => $user->vehicles()->where('status', 'active')->exists() ? '1' : '0',
                        'account_active' => $user->isActive() ? '1' : '0'
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get verification status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
