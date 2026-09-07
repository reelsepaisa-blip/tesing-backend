<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IssueReport;
use App\Models\Booking;
use App\Events\IssueReported;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Auth;

class IssueReportController extends Controller
{
    
    public function reportIssue(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => ['required', 'exists:bookings,id'],
            'issue_type' => ['required', 'string', 'in:' . implode(',', [
                IssueReport::ISSUE_TYPE_RIDER_DIDNT_SHOW_UP,
                IssueReport::ISSUE_TYPE_WRONG_PICKUP,
                IssueReport::ISSUE_TYPE_RIDER_DELAYED,
                IssueReport::ISSUE_TYPE_TRAFFIC_ISSUE,
                IssueReport::ISSUE_TYPE_NAVIGATION_PROBLEM,
                IssueReport::ISSUE_TYPE_CUSTOM,
            ])],
            'custom_issue' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', 'string', 'in:' . implode(',', [
                IssueReport::PRIORITY_LOW,
                IssueReport::PRIORITY_MEDIUM,
                IssueReport::PRIORITY_HIGH,
                IssueReport::PRIORITY_URGENT,
            ])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $driver = Auth::user();

            if (!$driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required',
                ], 401);
            }

            $booking = Booking::where('id', $request->booking_id)
                ->where('driver_id', $driver->id)
                ->first();

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found or you are not assigned to this booking',
                ], 404);
            }

            if (!in_array($booking->status, ['accepted', 'arrived', 'started'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot report issues for bookings in ' . $booking->status . ' status',
                ], 400);
            }

            if ($request->issue_type === IssueReport::ISSUE_TYPE_CUSTOM && empty($request->custom_issue)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Custom issue description is required when issue type is custom',
                ], 422);
            }

            $priority = $request->priority ?? $this->determinePriority($request->issue_type);

            $issueReport = IssueReport::create([
                'booking_id' => $booking->id,
                'driver_id' => $driver->id,
                'user_id' => $booking->user_id,
                'issue_type' => $request->issue_type,
                'custom_issue' => $request->custom_issue,
                'description' => $request->description,
                'status' => IssueReport::STATUS_REPORTED,
                'priority' => $priority,
                'reported_at' => now(),
                'meta_data' => [
                    'booking_status' => $booking->status,
                    'reported_by' => 'driver',
                    'driver_location' => $driver->currentLocation ? [
                        'latitude' => $driver->currentLocation->latitude,
                        'longitude' => $driver->currentLocation->longitude,
                    ] : null,
                ],
            ]);

            $issueReport->load(['booking.user', 'booking.rideType', 'driver.driverProfile', 'driver.vehicles']);

            event(new IssueReported($issueReport));

            DB::commit();

            

            return response()->json([
                'success' => true,
                'message' => 'Issue reported successfully',
                'data' => [
                    'issue_report' => [
                        'id' => (string) $issueReport->id,
                        'booking_id' => (string) $issueReport->booking_id,
                        'driver_id' => (string) $issueReport->driver_id,
                        'user_id' => (string) $issueReport->user_id,
                        'issue_type' => $issueReport->issue_type,
                        'custom_issue' => $issueReport->custom_issue ?? '',
                        'description' => $issueReport->description ?? '',
                        'status' => $issueReport->status,
                        'priority' => $issueReport->priority,
                        'issue_type_label' => $issueReport->issue_type_label,
                        'status_label' => $issueReport->status_label,
                        'priority_label' => $issueReport->priority_label,
                        'display_issue' => $issueReport->getDisplayIssue(),
                        'reported_at' => $issueReport->reported_at->toISOString(),
                        'resolved_at' => $issueReport->resolved_at ? $issueReport->resolved_at->toISOString() : '',
                        'resolution_note' => $issueReport->resolution_note ?? '',
                        'meta_data' => $issueReport->meta_data ?? [],
                    ],
                    'booking' => [
                        'id' => (string) $booking->id,
                        'booking_code' => $booking->booking_code,
                        'status' => $booking->status,
                        'pickup_address' => $booking->pickup_address,
                        'dropoff_address' => $booking->dropoff_address,
                    ],
                    'driver' => [
                        'id' => (string) $driver->id,
                        'name' => $driver->name,
                        'phone' => $driver->phone,
                        'bearer_token' => $driver->bearer_token ?? '',
                    ],
                    'user' => [
                        'id' => (string) $booking->user->id,
                        'name' => $booking->user->name,
                        'phone' => $booking->user->phone,
                        'bearer_token' => $booking->user->bearer_token ?? '',
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();


            return response()->json([
                'success' => false,
                'message' => 'Failed to report issue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function getBookingIssues(Request $request, $bookingId): JsonResponse
    {
        $validator = Validator::make(['booking_id' => $bookingId], [
            'booking_id' => ['required', 'exists:bookings,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid booking ID',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required',
                ], 401);
            }

            $booking = Booking::where('id', $bookingId)
                ->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->orWhere('driver_id', $user->id);
                })
                ->first();

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found or access denied',
                ], 404);
            }

            $issueReports = IssueReport::where('booking_id', $bookingId)
                ->with(['driver', 'user'])
                ->orderBy('reported_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Issue reports retrieved successfully',
                'data' => [
                    'booking_id' => (string) $bookingId,
                    'total_issues' => (string) $issueReports->count(),
                    'issue_reports' => $issueReports->map(function ($issueReport) use ($user) {
                        return [
                            'id' => (string) $issueReport->id,
                            'issue_type' => $issueReport->issue_type,
                            'custom_issue' => $issueReport->custom_issue ?? '',
                            'description' => $issueReport->description ?? '',
                            'status' => $issueReport->status,
                            'priority' => $issueReport->priority,
                            'issue_type_label' => $issueReport->issue_type_label,
                            'status_label' => $issueReport->status_label,
                            'priority_label' => $issueReport->priority_label,
                            'display_issue' => $issueReport->getDisplayIssue(),
                            'reported_at' => $issueReport->reported_at->toISOString(),
                            'resolved_at' => $issueReport->resolved_at ? $issueReport->resolved_at->toISOString() : '',
                            'resolution_note' => $issueReport->resolution_note ?? '',
                            'reported_by' => $issueReport->driver_id === $user->id ? 'driver' : 'user',
                            'driver' => [
                                'id' => (string) $issueReport->driver->id,
                                'name' => $issueReport->driver->name,
                                'phone' => $issueReport->driver->phone,
                            ],
                            'user' => [
                                'id' => (string) $issueReport->user->id,
                                'name' => $issueReport->user->name,
                                'phone' => $issueReport->user->phone,
                            ],
                        ];
                    })->toArray(),
                ],
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve issue reports',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function getIssueTypes(): JsonResponse
    {
        $issueTypes = [
            [
                'value' => IssueReport::ISSUE_TYPE_RIDER_DIDNT_SHOW_UP,
                'label' => 'Rider Didn\'t Show Up',
                'icon' => 'close',
                'description' => 'The rider was not at the pickup location',
            ],
            [
                'value' => IssueReport::ISSUE_TYPE_WRONG_PICKUP,
                'label' => 'Wrong Pickup',
                'icon' => 'location',
                'description' => 'The pickup location is incorrect',
            ],
            [
                'value' => IssueReport::ISSUE_TYPE_RIDER_DELAYED,
                'label' => 'Rider is Delayed',
                'icon' => 'time',
                'description' => 'The rider is running late',
            ],
            [
                'value' => IssueReport::ISSUE_TYPE_TRAFFIC_ISSUE,
                'label' => 'Traffic Issue',
                'icon' => 'traffic',
                'description' => 'Stuck in traffic or road closure',
            ],
            [
                'value' => IssueReport::ISSUE_TYPE_NAVIGATION_PROBLEM,
                'label' => 'Navigation Problem',
                'icon' => 'navigation',
                'description' => 'GPS or navigation issues',
            ],
            [
                'value' => IssueReport::ISSUE_TYPE_CUSTOM,
                'label' => 'Other Issue',
                'icon' => 'help',
                'description' => 'Describe your issue',
            ],
        ];

        return response()->json([
            'success' => true,
            'message' => 'Issue types retrieved successfully',
            'data' => [
                'issue_types' => $issueTypes,
            ],
        ]);
    }

    
    private function determinePriority(string $issueType): string
    {
        $priorityMap = [
            IssueReport::ISSUE_TYPE_TRAFFIC_ISSUE => IssueReport::PRIORITY_MEDIUM,
            IssueReport::ISSUE_TYPE_NAVIGATION_PROBLEM => IssueReport::PRIORITY_MEDIUM,
            IssueReport::ISSUE_TYPE_RIDER_DIDNT_SHOW_UP => IssueReport::PRIORITY_MEDIUM,
            IssueReport::ISSUE_TYPE_WRONG_PICKUP => IssueReport::PRIORITY_MEDIUM,
            IssueReport::ISSUE_TYPE_RIDER_DELAYED => IssueReport::PRIORITY_LOW,
            IssueReport::ISSUE_TYPE_CUSTOM => IssueReport::PRIORITY_MEDIUM,
        ];

        return $priorityMap[$issueType] ?? IssueReport::PRIORITY_MEDIUM;
    }
}
