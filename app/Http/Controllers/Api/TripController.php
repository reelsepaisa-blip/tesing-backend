<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\TripService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TripController extends Controller
{
    protected TripService $tripService;

    public function __construct(TripService $tripService)
    {
        $this->tripService = $tripService;
    }


    public function getCurrentTrip(Request $request): JsonResponse
    {
        $user = $request->user();

        $booking = Booking::where('user_id', $user->id)
            ->whereIn('status', ['searching', 'accepted', 'arrived', 'started'])
            ->with(['driver', 'driver.vehicles', 'rideType'])
            ->latest()
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'No active trip found',
            ], 404);
        }

        $tripStatus = $this->tripService->getTripStatus($booking);

        return response()->json([
            'success' => true,
            'trip' => $tripStatus,
        ]);
    }


    public function startTrip(Request $request, Booking $booking): JsonResponse
    {
        $actor = $request->user();
        if (!$actor || (int) $booking->driver_id !== (int) $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this trip',
            ], 403);
        }

        $request->validate([
            'trip_code' => ['required', 'string', 'size:4'],
        ]);

        try {
            $this->tripService->startTrip($booking, $request->trip_code);

            return response()->json([
                'success' => true,
                'message' => 'Trip started successfully',
                'trip' => $this->tripService->getTripStatus($booking),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid trip code',
                'errors' => $e->errors(),
            ], 422);
        }
    }


    public function completeTrip(Request $request, Booking $booking): JsonResponse
    {
        $actor = $request->user();
        if (!$actor || (int) $booking->driver_id !== (int) $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this trip',
            ], 403);
        }

        $request->validate([
            'actual_distance' => ['required', 'numeric', 'min:0'],
            'actual_duration' => ['required', 'integer', 'min:0'],
            'waiting_time' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $this->tripService->completeTrip($booking, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Trip completed successfully',
                'trip' => $this->tripService->getTripStatus($booking),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot complete trip',
                'errors' => $e->errors(),
            ], 422);
        }
    }


    public function updateLocation(Request $request, Booking $booking): JsonResponse
    {
        $actor = $request->user();
        if (!$actor || (int) $booking->driver_id !== (int) $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this trip',
            ], 403);
        }

        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $updated = $this->tripService->updateDriverLocation(
            $booking,
            $request->latitude,
            $request->longitude
        );

        if (!$updated) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update location for this trip status',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully',
        ]);
    }


    public function rateDriver(Request $request, Booking $booking): JsonResponse
    {
        $actor = $request->user();
        if (!$actor || (int) $booking->user_id !== (int) $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this trip',
            ], 403);
        }

        $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'review' => ['nullable', 'string', 'max:500'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'in:clean_vehicle,friendly_driver,safe_driver,good_navigation,professional'],
        ]);

        if ($booking->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Can only rate completed trips',
            ], 422);
        }

        if ($booking->user_rating) {
            return response()->json([
                'success' => false,
                'message' => 'Driver already rated for this trip',
            ], 422);
        }

        $booking->update([
            'user_rating' => $request->rating,
            'user_review' => $request->review,
            'meta_data' => array_merge($booking->meta_data ?? [], [
                'rating_tags' => $request->tags ?? [],
            ]),
        ]);

        $this->updateDriverRating($booking->driver);

        return response()->json([
            'success' => true,
            'message' => 'Driver rated successfully',
        ]);
    }


    public function rateUser(Request $request, Booking $booking): JsonResponse
    {
        $actor = $request->user();
        if (!$actor || (int) $booking->driver_id !== (int) $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this trip',
            ], 403);
        }

        $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'review' => ['nullable', 'string', 'max:500'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'in:punctual,polite,clean,good_communication'],
        ]);

        if ($booking->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Can only rate completed trips',
            ], 422);
        }

        if ($booking->driver_rating) {
            return response()->json([
                'success' => false,
                'message' => 'User already rated for this trip',
            ], 422);
        }

        $booking->update([
            'driver_rating' => $request->rating,
            'driver_review' => $request->review,
            'meta_data' => array_merge($booking->meta_data ?? [], [
                'driver_rating_tags' => $request->tags ?? [],
            ]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User rated successfully',
        ]);
    }


    public function getTripInfo(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:completed,cancelled,pending,searching,accepted,arrived,started',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $user = $request->user();
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);

        $currentTrip = Booking::where('user_id', $user->id)
            ->whereIn('status', ['searching', 'accepted', 'arrived', 'started'])
            ->with(['driver', 'driver.vehicles', 'rideType'])
            ->latest()
            ->first();

        $currentTripData = '';
        if ($currentTrip) {
            $currentTripData = $this->tripService->getTripStatus($currentTrip);
        }

        $bookingsQuery = Booking::where('user_id', $user->id)
            ->where('is_confirm', 1)
            ->with([
                'user',
                'driver.driverProfile',
                'driver.vehicles.rideType',
                'rideType',
                'pickupZone',
                'dropoffZone',
                'transactions',
                'user.wallet',
                'latestRefundRequest',
                'promoUsage.promoCode',
            ])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $bookingsQuery->where('status', $request->input('status'));
        }

        if ($request->has('date_from')) {
            $bookingsQuery->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $bookingsQuery->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $bookings = $bookingsQuery->paginate($perPage, ['*'], 'page', $page);

        $tripHistory = $bookings->map(function ($booking) {
            $fareBreakdown = $this->calculateFareBreakdown($booking);

            return [
                'refund_status' => optional($booking->latestRefundRequest)->status ?? '',
                'trip_info' => [
                    'id' => (string) $booking->id,
                    'booking_code' => $booking->booking_code ?? '',
                    'trip_code' => $booking->trip_code ?? '',
                    'otp' => $booking->otp ?? '',
                    'status' => $booking->status ?? '',
                    'payment_method' => $booking->payment_method ?? '',
                    'payment_status' => $booking->payment_status ?? '',
                    'created_at' => $booking->created_at ? $booking->created_at->toISOString() : '',
                    'scheduled_at' => $booking->scheduled_at ? $booking->scheduled_at->toISOString() : '',
                    'started_at' => $booking->started_at ? $booking->started_at->toISOString() : '',
                    'completed_at' => $booking->completed_at ? $booking->completed_at->toISOString() : '',
                    'cancelled_at' => $booking->cancelled_at ? $booking->cancelled_at->toISOString() : '',
                    'cancellation_reason' => $booking->cancellation_reason ?? '',
                    'cancelled_by_type' => $booking->cancelled_by_type ?? '',
                    'status_label' => $this->getStatusLabel($booking->status ?? ''),
                    'booking_date_time' => $booking->created_at ? $booking->created_at->format('d M Y, h:i A') : '',
                    'invoice_download_link' => $booking->status === 'completed'
                        ? url('/api/trips/' . $booking->id . '/invoice')
                        : '',
                ],
                'locations' => [
                    'pickup_address' => $booking->pickup_address ?? '',
                    'pickup_latitude' => (string) ($booking->pickup_latitude ?? ''),
                    'pickup_longitude' => (string) ($booking->pickup_longitude ?? ''),
                    'dropoff_address' => $booking->dropoff_address ?? '',
                    'dropoff_latitude' => (string) ($booking->dropoff_latitude ?? ''),
                    'dropoff_longitude' => (string) ($booking->dropoff_longitude ?? ''),
                    'estimated_distance' => (string) ($booking->estimated_distance ?? '0'),
                    'actual_distance' => (string) ($booking->actual_distance ?? '0'),
                    'estimated_duration' => (string) ($booking->estimated_duration ?? '0'),
                    'actual_duration' => (string) ($booking->actual_duration ?? '0'),
                    'distance_km' => $this->formatDistance($booking->actual_distance ?? $booking->estimated_distance ?? 0),
                    'trip_time_minutes' => $this->formatTripTime($booking->actual_duration ?? $booking->estimated_duration ?? 0),
                ],
                'time_info' => [
                    'total_trip_duration' => $this->calculateTotalTripDuration($booking),
                    'waiting_time' => (string) ($booking->waiting_time ?? '0'),
                    'total_waiting_time' => (string) ($booking->total_waiting_time ?? '0'),
                    'driver_arrival_time' => $booking->driver_arrival_time ? $booking->driver_arrival_time->toISOString() : '',
                    'pickup_time' => $booking->pickup_time ? $booking->pickup_time->toISOString() : '',
                    'dropoff_time' => $booking->dropoff_time ? $booking->dropoff_time->toISOString() : '',
                    'time_breakdown' => $this->getTimeBreakdown($booking),
                ],
                'fare_breakdown' => [
                    'items' => [
                        [
                            'description' => 'Base Fare',
                            'amount' => number_format($fareBreakdown['base_fare'], 2),
                            'amount_raw' => (string) $fareBreakdown['base_fare'],
                        ],
                        [
                            'description' => 'Distance Fare',
                            'amount' => number_format($fareBreakdown['distance_fare'], 2),
                            'amount_raw' => (string) $fareBreakdown['distance_fare'],
                        ],
                        [
                            'description' => 'Time Fare',
                            'amount' => number_format($fareBreakdown['time_fare'], 2),
                            'amount_raw' => (string) $fareBreakdown['time_fare'],
                        ],
                        [
                            'description' => 'Waiting Charge',
                            'amount' => number_format($fareBreakdown['waiting_charge'], 2),
                            'amount_raw' => (string) $fareBreakdown['waiting_charge'],
                        ],
                        [
                            'description' => 'Night Charge',
                            'amount' => number_format($fareBreakdown['night_charge'], 2),
                            'amount_raw' => (string) $fareBreakdown['night_charge'],
                        ],
                        [
                            'description' => 'Surge Amount',
                            'amount' => number_format($fareBreakdown['surge_amount'], 2),
                            'amount_raw' => (string) $fareBreakdown['surge_amount'],
                        ],
                        [
                            'description' => 'Tax',
                            'amount' => number_format($fareBreakdown['tax_amount'], 2),
                            'amount_raw' => (string) $fareBreakdown['tax_amount'],
                        ],
                    ],
                    'promo_code' => $fareBreakdown['promo_code'] ?? '',
                    'promo_description' => $fareBreakdown['promo_description'] ?? '',
                    'discount_amount' => number_format($fareBreakdown['discount_amount'], 2),
                    'discount_amount_raw' => (string) $fareBreakdown['discount_amount'],
                    'has_promo' => !empty($fareBreakdown['promo_code']) && $fareBreakdown['discount_amount'] > 0,
                    'subtotal' => number_format($fareBreakdown['subtotal'], 2),
                    'subtotal_raw' => (string) $fareBreakdown['subtotal'],
                    'total_amount' => number_format($fareBreakdown['total_amount'], 2),
                    'total_amount_raw' => (string) $fareBreakdown['total_amount'],
                    'currency_symbol' => '₹',
                ],
                'pricing' => [
                    'base_fare' => (string) ($booking->base_fare ?? '0'),
                    'distance_fare' => (string) ($booking->distance_fare ?? '0'),
                    'time_fare' => (string) ($booking->time_fare ?? '0'),
                    'waiting_charge' => (string) ($booking->waiting_charge ?? '0'),
                    'cancellation_charge' => (string) ($booking->cancellation_charge ?? '0'),
                    'night_charge' => (string) ($booking->night_charge ?? '0'),
                    'surge_multiplier' => (string) ($booking->surge_multiplier ?? '1'),
                    'surge_amount' => (string) ($booking->surge_amount ?? '0'),
                    'total_fare' => (string) ($booking->total_fare ?? '0'),
                    'final_fare' => (string) ($booking->final_fare ?? '0'),
                    'discount_amount' => (string) ($booking->discount_amount ?? '0'),
                    'promo_discount' => (string) ($booking->promo_discount ?? '0'),
                    'tip_amount' => (string) ($booking->tip_amount ?? '0'),
                    'ride_fare' => $this->formatRideFare($booking->total_amount ?? $booking->final_fare ?? $booking->total_fare ?? $booking->estimated_fare ?? 0),
                ],
                'driver_info' => $booking->driver ? [
                    'id' => (string) $booking->driver->id,
                    'name' => $booking->driver->name ?? '',
                    'phone' => $booking->driver->phone ?? '',
                    'rating' => (string) ($booking->driver->driverProfile->rating ?? '0'),
                    'driver_rating' => $this->formatDriverRating($booking->driver->driverProfile->rating ?? 0),
                    'profile_photo' => $this->getImageUrl($booking->driver->profile_photo),
                    'vehicle' => $booking->driver->vehicles->first() ? [
                        'model' => $booking->driver->vehicles->first()->model ?? '',
                        'vehicle_model' => $booking->driver->vehicles->first()->model ?? '',
                        'color' => $booking->driver->vehicles->first()->color ?? '',
                        'vehicle_plate_number' => $booking->driver->vehicles->first()->registration_number ?? '',
                    ] : '',
                ] : '',
                'ride_type' => $booking->rideType ? [
                    'id' => (string) $booking->rideType->id,
                    'name' => $booking->rideType->name ?? '',
                    'ride_type_name' => $booking->rideType->name ?? '',
                    'description' => $booking->rideType->description ?? '',
                    'base_fare' => (string) ($booking->rideType->base_fare ?? '0'),
                    'icon' => $this->getImageUrl($booking->rideType->icon),
                ] : null,

                'ratings' => [
                    'user_rating' => (string) ($booking->user_rating ?? '0'),
                    'user_review' => $booking->user_review ?? '',
                    'driver_rating' => (string) ($booking->driver_rating ?? '0'),
                    'driver_review' => $booking->driver_review ?? '',
                ],
                'transactions' => $booking->transactions->map(function ($transaction) {
                    return [
                        'id' => (string) $transaction->id,
                        'type' => $transaction->type ?? '',
                        'amount' => (string) ($transaction->amount ?? '0'),
                        'status' => $transaction->status ?? '',
                        'payment_method' => $transaction->payment_method ?? '',
                        'created_at' => $transaction->created_at ? $transaction->created_at->toISOString() : '',
                    ];
                }),
            ];
        });

        $allBookingsQuery = Booking::where('user_id', $user->id)
            ->where('is_confirm', 1);

        if ($request->has('status')) {
            $allBookingsQuery->where('status', $request->input('status'));
        }
        if ($request->has('date_from')) {
            $allBookingsQuery->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $allBookingsQuery->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $allBookings = $allBookingsQuery->get();

        $overallStats = [
            'total_trips' => $allBookings->count(),
            'completed_trips' => $allBookings->where('status', 'completed')->count(),
            'cancelled_trips' => $allBookings->where('status', 'cancelled')->count(),
            'total_spent' => $allBookings->where('status', 'completed')->sum('final_fare'),
            'average_rating_given' => $allBookings->where('user_rating', '>', 0)->avg('user_rating') ?: 0,
            'average_rating_received' => $allBookings->where('driver_rating', '>', 0)->avg('driver_rating') ?: 0,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'current_trip' => $currentTripData,
                'has_active_trip' => $currentTrip !== null,
                'trip_history' => $tripHistory,
                'stats' => $overallStats,
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                    'last_page' => $bookings->lastPage(),
                    'from' => $bookings->firstItem(),
                    'to' => $bookings->lastItem(),
                    'has_more_pages' => $bookings->hasMorePages(),
                ],
            ]
        ]);
    }


    public function downloadInvoice(Request $request, Booking $booking)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $isOwner = (int) $booking->user_id === (int) $user->id || (int) $booking->driver_id === (int) $user->id;
        $isAdmin = ((int) ($user->role_id ?? 0) === 1)
            || (method_exists($user, 'hasRole') && $user->hasRole('admin'));
        if (!$isOwner && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to access this invoice',
            ], 403);
        }

        if ($booking->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice is only available for completed trips',
            ], 400);
        }

        $booking->load(['user', 'driver.vehicles', 'promoUsage.promoCode']);

        $invoiceData = $this->generateInvoiceData($booking);

        // clear any previous output that might corrupt the PDF
        if (ob_get_length()) {
            ob_end_clean();
        }

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);

        $html = $this->generateInvoicePdfHtml($invoiceData);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();

        $filename = 'invoice-' . $invoiceData['invoice_number'] . '.pdf';

        return response($output, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Content-Length', strlen($output));
    }


    private function generateInvoicePdfHtml(array $invoiceData): string
    {
        $subtotalBeforeDiscount = ($invoiceData['fare_breakdown']['subtotal'] ?? 0) + ($invoiceData['fare_breakdown']['discount_amount'] ?? 0);
        $hasPromo = !empty($invoiceData['fare_breakdown']['promo_code']) && ($invoiceData['fare_breakdown']['discount_amount'] ?? 0) > 0;

        $promoHtml = '';
        if ($hasPromo) {
            $promoDescription = !empty($invoiceData['fare_breakdown']['promo_description'])
                ? '<br><small style="color: #666;">' . htmlspecialchars($invoiceData['fare_breakdown']['promo_description']) . '</small>'
                : '';
            $promoHtml = '
                <tr style="color: #28a745;">
                    <td>Promo Code: <strong>' . htmlspecialchars($invoiceData['fare_breakdown']['promo_code']) . '</strong>' . $promoDescription . '</td>
                    <td style="color: #28a745; text-align: right;"><strong>-Rs. ' . number_format($invoiceData['fare_breakdown']['discount_amount'], 2) . '</strong></td>
                </tr>
                <tr style="display: none;">
                    <td><strong>Subtotal After Discount</strong></td>
                    <td style="text-align: right;"><strong>Rs. ' . number_format($invoiceData['fare_breakdown']['subtotal'], 2) . '</strong></td>
                </tr>';
        }

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #' . htmlspecialchars($invoiceData['invoice_number']) . '</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            margin: 0;
            padding: 15px;
            font-size: 11px;
            color: #333;
        }
        .invoice-container {
            max-width: 100%;
            margin: 0 auto;
        }
        .invoice-header {
            background-color: #f1a309 !important;
            color: black !important;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .invoice-header h1 {
            margin: 0;
            font-size: 24px;
        }
        .invoice-header p {
            margin: 5px 0 0 0;
        }
        .info-grid {
            width: 100%;
            margin-bottom: 15px;
        }
        .info-grid td {
            width: 50%;
            vertical-align: top;
            padding: 8px;
        }
        .info-section h3 {
            color: #333;
            margin-bottom: 8px;
            font-size: 12px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 4px;
        }
        .info-section p {
            margin: 3px 0;
            color: #555;
        }
        .trip-details {
            background: #f8f9fa;
            padding: 12px;
            margin-bottom: 15px;
        }
        .trip-details h3 {
            color: #333;
            margin-bottom: 8px;
            font-size: 12px;
        }
        .fare-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .fare-table th, .fare-table td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .fare-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .fare-table td:last-child {
            text-align: right;
        }
        .total-row {
            background-color: #667eea;
            color: white;
            font-weight: bold;
        }
        .total-row td {
            border-bottom: none;
        }
        .payment-details {
            background: #e8f5e8;
            padding: 12px;
            border-left: 4px solid #28a745;
            margin-bottom: 15px;
        }
        .payment-details h3 {
            color: #333;
            margin-bottom: 8px;
            font-size: 12px;
        }
        .footer {
            text-align: center;
            margin-top: 15px;
            color: #666;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <table style="width: 100%; background-color: #667eea; margin-bottom: 20px;">
            <tr>
                <td style="padding: 20px; text-align: center; color: #ffffff;">
                    <div style="font-size: 24px; font-weight: bold; color: #ffffff;">eTaxi</div>
                    <div style="margin-top: 5px; color: #ffffff;">Taxi Booking Invoice</div>
                    <div style="margin-top: 5px; color: #ffffff;">Invoice #' . htmlspecialchars($invoiceData['invoice_number']) . '</div>
                </td>
            </tr>
        </table>

        <table class="info-grid">
            <tr>
                <td>
                    <div class="info-section">
                        <h3>Customer Details</h3>
                        <p><strong>Name:</strong> ' . htmlspecialchars($invoiceData['customer']['name']) . '</p>
                        <p><strong>Phone:</strong> ' . htmlspecialchars($invoiceData['customer']['phone']) . '</p>
                        <p><strong>Email:</strong> ' . htmlspecialchars($invoiceData['customer']['email']) . '</p>
                    </div>
                </td>
                <td>
                    <div class="info-section">
                        <h3>Driver Details</h3>
                        <p><strong>Name:</strong> ' . htmlspecialchars($invoiceData['driver']['name']) . '</p>
                        <p><strong>Phone:</strong> ' . htmlspecialchars($invoiceData['driver']['phone']) . '</p>
                        <p><strong>Vehicle Model:</strong> ' . htmlspecialchars($invoiceData['driver']['vehicle']) . '</p>
                        <p><strong>Vehicle Registration Number:</strong> ' . htmlspecialchars($invoiceData['driver']['license_plate']) . '</p>
                    </div>
                </td>
            </tr>
        </table>

        <div class="trip-details">
            <h3>Trip Details</h3>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        <p><strong>Pickup:</strong> ' . htmlspecialchars($invoiceData['trip_details']['pickup_address']) . '</p>
                        <p><strong>Dropoff:</strong> ' . htmlspecialchars($invoiceData['trip_details']['dropoff_address']) . '</p>
                    </td>
                    <td style="width: 50%; vertical-align: top;">
                        <p><strong>Distance:</strong> ' . htmlspecialchars($invoiceData['trip_details']['distance']) . '</p>
                        <p><strong>Duration:</strong> ' . htmlspecialchars($invoiceData['trip_details']['duration']) . '</p>
                        <p><strong>Started:</strong> ' . htmlspecialchars($invoiceData['trip_details']['started_at'] ?? 'N/A') . '</p>
                        <p><strong>Completed:</strong> ' . htmlspecialchars($invoiceData['trip_details']['completed_at'] ?? 'N/A') . '</p>
                    </td>
                </tr>
            </table>
        </div>

        <h3 style="border-bottom: 2px solid #667eea; padding-bottom: 4px; margin-bottom: 8px; font-size: 12px;">Fare Breakdown</h3>
        <table class="fare-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align: right;">Amount (Rs)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Base Fare</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['base_fare'], 2) . '</td>
                </tr>
                <tr>
                    <td>Distance Fare</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['distance_fare'], 2) . '</td>
                </tr>
                <tr>
                    <td>Time Fare</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['time_fare'], 2) . '</td>
                </tr>
                <tr>
                    <td>Waiting Charge</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['waiting_charge'], 2) . '</td>
                </tr>
                <tr>
                    <td>Night Charge</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['night_charge'], 2) . '</td>
                </tr>
                <tr>
                    <td>Surge Amount</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['surge_amount'], 2) . '</td>
                </tr>
                ' . ($invoiceData['fare_breakdown']['additional_fare'] > 0 ? '
                <tr>
                    <td>Additional Fare</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['additional_fare'], 2) . '</td>
                </tr>' : '') . '
                ' . ($invoiceData['fare_breakdown']['debt_amount'] > 0 ? '
                <tr>
                    <td>Outstanding Debt</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['debt_amount'], 2) . '</td>
                </tr>' : '') . '
                <tr style="display: none;">
                    <td><strong>Subtotal</strong></td>
                    <td style="text-align: right;"><strong>' . number_format($subtotalBeforeDiscount, 2) . '</strong></td>
                </tr>
                ' . $promoHtml . '
                <tr>
                    <td>Tax</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['tax_amount'], 2) . '</td>
                </tr>
                ' . (($invoiceData['fare_breakdown']['tip_amount'] ?? 0) > 0 ? '
                <tr>
                    <td>Tip Amount</td>
                    <td style="text-align: right;">' . number_format($invoiceData['fare_breakdown']['tip_amount'], 2) . '</td>
                </tr>' : '') . '
                <tr class="total-row">
                    <td><strong>Total Amount</strong></td>
                    <td style="text-align: right;"><strong>Rs. ' . number_format($invoiceData['fare_breakdown']['total_amount'], 2) . '</strong></td>
                </tr>
            </tbody>
        </table>

        <h3 style="border-bottom: 2px solid #667eea; padding-bottom: 4px; margin-bottom: 8px; margin-top: 20px; font-size: 12px;">Payment Details</h3>
        <div class="payment-details">
            ' . ($invoiceData['payment_details']['is_split_payment'] && strtolower($invoiceData['payment_details']['payment_method'] ?? '') !== 'wallet' ? '
            <p><strong>Payment Method:</strong> Wallet + ' . htmlspecialchars(ucfirst($invoiceData['payment_details']['payment_method'] ?? 'Online')) . '</p>
            <p><strong>Payment Status:</strong> ' . htmlspecialchars(ucfirst($invoiceData['payment_details']['payment_status'] ?? 'N/A')) . '</p>
            <table class="fare-table" style="margin-top: 10px;">
                <tr>
                    <td><strong>Amount Paid from Wallet</strong></td>
                    <td style="text-align: right;"><strong>Rs. ' . number_format($invoiceData['payment_details']['wallet_amount'], 2) . '</strong></td>
                </tr>
                <tr>
                    <td><strong>Amount Paid via ' . htmlspecialchars(ucfirst($invoiceData['payment_details']['payment_method'] ?? 'Online')) . '</strong></td>
                    <td style="text-align: right;"><strong>Rs. ' . number_format($invoiceData['payment_details']['online_paid_amount'], 2) . '</strong></td>
                </tr>
                <tr class="total-row">
                    <td><strong>Total Paid</strong></td>
                    <td style="text-align: right;"><strong>Rs. ' . number_format($invoiceData['payment_details']['wallet_amount'] + $invoiceData['payment_details']['online_paid_amount'], 2) . '</strong></td>
                </tr>
            </table>
            ' : '
            <p><strong>Payment Method:</strong> ' . htmlspecialchars(ucfirst($invoiceData['payment_details']['payment_method'] ?? 'N/A')) . '</p>
            <p><strong>Payment Status:</strong> ' . htmlspecialchars(ucfirst($invoiceData['payment_details']['payment_status'] ?? 'N/A')) . '</p>
            <p><strong>Amount Paid:</strong> Rs. ' . number_format($invoiceData['fare_breakdown']['total_amount'], 2) . '</p>
            ') . '
        </div>

        <div class="footer">
            <p>Thank you for riding with eTaxi!</p>
            <p>This is a computer-generated invoice and does not require a signature.</p>
        </div>
    </div>
</body>
</html>';
    }


    private function calculateFareBreakdown(Booking $booking): array
    {
        $baseFare = (float) ($booking->base_fare ?? 0);
        $distanceFare = (float) ($booking->distance_fare ?? 0);
        $timeFare = (float) ($booking->time_fare ?? 0);
        $waitingCharge = (float) ($booking->waiting_charge ?? 0);
        $nightCharge = (float) ($booking->night_charge ?? 0);
        $surgeAmount = (float) ($booking->surge_amount ?? 0);
        $discountAmount = (float) ($booking->discount_amount ?? 0);
        $taxAmount = (float) ($booking->tax_amount ?? 0);
        $debtAmount = (float) ($booking->debt_amount ?? 0);
        $tipAmount = (float) ($booking->tip_amount ?? 0);
        $driverAmount = (float) ($booking->driver_amount ?? 0);
        $adminCommission = (float) ($booking->admin_commission ?? 0);

        $totalAmount = (float) ($booking->total_amount ?? 0);

        if ($totalAmount <= 0) {
            $totalAmount = $driverAmount + $adminCommission;
            if ($totalAmount <= 0) {
                $totalAmount = (float) ($booking->final_fare ?? 0);
            }
        }

        $knownFareSum = $baseFare + $distanceFare + $timeFare + $waitingCharge + $nightCharge + $surgeAmount;

        $subtotal = (float) ($booking->subtotal ?? ($totalAmount + $discountAmount - $taxAmount));

        if ($subtotal <= 0) {
            $subtotal = $knownFareSum;
        }

        $additionalFare = max(0, $subtotal - $knownFareSum - $debtAmount);

        return [
            'base_fare' => $baseFare,
            'distance_fare' => $distanceFare,
            'time_fare' => $timeFare,
            'waiting_charge' => $waitingCharge,
            'night_charge' => $nightCharge,
            'surge_amount' => $surgeAmount,
            'additional_fare' => $additionalFare,
            'debt_amount' => $debtAmount,
            'tip_amount' => $tipAmount,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'promo_code' => $booking->promo_code,
            'promo_description' => $booking->promoUsage?->promoCode?->description ?? null,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
        ];
    }


    private function generateInvoiceData(Booking $booking): array
    {
        $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT);

        return [
            'invoice_number' => $invoiceNumber,
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'invoice_date' => $booking->completed_at?->format('Y-m-d H:i:s'),
            'customer' => [
                'name' => $booking->user->name ?? '',
                'phone' => $booking->user->phone ?? '',
                'email' => $booking->user->email ?? '',
            ],
            'driver' => [
                'name' => $booking->driver->name ?? '',
                'phone' => $booking->driver->phone ?? '',
                'vehicle' => $booking->driver->vehicles->first()->model ?? 'N/A',
                'license_plate' => $booking->driver->vehicles->first()->registration_number ?? 'N/A',
            ],
            'trip_details' => [
                'pickup_address' => $booking->pickup_address,
                'dropoff_address' => $booking->dropoff_address,
                'distance' => $this->getBillingDistance($booking) . ' km',
                'duration' => $this->getBillingDuration($booking) . ' minutes',
                'started_at' => $booking->started_at?->format('Y-m-d H:i:s'),
                'completed_at' => $booking->completed_at?->format('Y-m-d H:i:s'),
            ],
            'fare_breakdown' => $this->calculateFareBreakdown($booking),
            'payment_details' => $this->generatePaymentDetails($booking),
        ];
    }


    private function generatePaymentDetails(Booking $booking): array
    {
        $walletAmount = (float) ($booking->wallet_amount ?? 0);
        $onlinePaidAmount = (float) ($booking->online_paid_amount ?? 0);
        $isSplitPayment = $walletAmount > 0 && $onlinePaidAmount > 0;

        // Get original payment method from meta_data if it's a split payment
        $originalPaymentMethod = '';
        if ($booking->payment_method === 'split') {
            $metaData = $booking->meta_data ?? [];
            $originalPaymentMethod = $metaData['original_payment_method'] ?? '';
        }

        // For split payments, use original payment method for display, otherwise use booking payment_method
        $displayPaymentMethod = $isSplitPayment && $originalPaymentMethod
            ? $originalPaymentMethod
            : ($booking->payment_method ?? '');

        return [
            'payment_method' => $displayPaymentMethod,
            'payment_status' => $booking->payment_status ?? '',
            'driver_amount' => $booking->driver_amount ?? 0,
            'platform_commission' => $booking->admin_commission ?? 0,
            'driver_commission_rate' => $booking->driver_amount > 0 && $booking->total_amount > 0
                ? round((($booking->driver_amount / $booking->total_amount) * 100), 1) . '%'
                : '0%',
            'platform_commission_rate' => $booking->admin_commission > 0 && $booking->total_amount > 0
                ? round((($booking->admin_commission / $booking->total_amount) * 100), 1) . '%'
                : '0%',
            'wallet_amount' => $walletAmount,
            'online_paid_amount' => $onlinePaidAmount,
            'is_split_payment' => $isSplitPayment,
        ];
    }


    private function calculateTotalTripDuration($booking): string
    {
        if (!$booking->started_at || !$booking->completed_at) {
            return '0';
        }

        $startTime = $booking->started_at;
        $endTime = $booking->completed_at;

        $duration = $endTime->diffInMinutes($startTime);
        return (string) $duration;
    }


    private function getTimeBreakdown($booking): array
    {
        $breakdown = [
            'estimated_vs_actual' => [
                'estimated_duration' => (string) ($booking->estimated_duration ?? '0'),
                'actual_duration' => (string) ($booking->actual_duration ?? '0'),
                'duration_difference' => '0',
            ],
            'waiting_times' => [
                'driver_waiting_time' => (string) ($booking->waiting_time ?? '0'),
                'total_waiting_time' => (string) ($booking->total_waiting_time ?? '0'),
            ],
            'trip_phases' => [
                'booking_to_driver_assigned' => $this->calculatePhaseDuration($booking->created_at, $booking->driver_arrival_time),
                'driver_arrival_to_pickup' => $this->calculatePhaseDuration($booking->driver_arrival_time, $booking->pickup_time),
                'pickup_to_dropoff' => $this->calculatePhaseDuration($booking->pickup_time, $booking->dropoff_time),
                'total_trip_time' => $this->calculateTotalTripDuration($booking),
            ]
        ];

        if ($booking->estimated_duration && $booking->actual_duration) {
            $breakdown['estimated_vs_actual']['duration_difference'] = (string) ($booking->actual_duration - $booking->estimated_duration);
        }

        return $breakdown;
    }


    private function calculatePhaseDuration($startTime, $endTime): string
    {
        if (!$startTime || !$endTime) {
            return '0';
        }

        if ($startTime instanceof \Carbon\Carbon && $endTime instanceof \Carbon\Carbon) {
            return (string) $startTime->diffInMinutes($endTime);
        }

        return '0';
    }


    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending',
            'searching' => 'Searching for Driver',
            'accepted' => 'Driver Assigned',
            'arrived' => 'Driver Arrived',
            'started' => 'Trip Started',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_driver_found' => 'No Driver Found',
            'driver_cancelled' => 'Driver Cancelled',
            'user_cancelled' => 'User Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }


    private function formatDistance($distance): string
    {
        if (!$distance || $distance == 0) {
            return '0Km';
        }
        return number_format($distance, 1) . 'Km';
    }


    private function formatTripTime($duration): string
    {
        if (!$duration || $duration == 0) {
            return '0 Min';
        }
        return $duration . ' Min';
    }


    private function formatRideFare($fare): string
    {
        if (!$fare || $fare == 0) {
            return '₹0';
        }
        return '₹' . number_format($fare, 0);
    }


    private function formatDriverRating($rating): string
    {
        if (!$rating || $rating == 0) {
            return '0';
        }
        return number_format($rating, 1);
    }


    private function getImageUrl($imagePath): string
    {
        if (!$imagePath) {
            return '';
        }

        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        return asset('storage/' . $imagePath);
    }


    public function getTripHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        $bookings = Booking::where('user_id', $user->id)
            ->with([
                'user',
                'driver.driverProfile',
                'driver.vehicles.rideType',
                'rideType',
                'pickupZone',
                'dropoffZone',
                'transactions',
                'user.wallet'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $trips = $bookings->map(function ($booking) {
            return [
                'trip_info' => [
                    'id' => (string) $booking->id,
                    'booking_code' => $booking->booking_code ?? '',
                    'trip_code' => $booking->trip_code ?? '',
                    'status' => $booking->status ?? '',
                    'payment_method' => $booking->payment_method ?? '',
                    'payment_status' => $booking->payment_status ?? '',
                    'created_at' => $booking->created_at ? $booking->created_at->toISOString() : '',
                    'scheduled_at' => $booking->scheduled_at ? $booking->scheduled_at->toISOString() : '',
                    'started_at' => $booking->started_at ? $booking->started_at->toISOString() : '',
                    'completed_at' => $booking->completed_at ? $booking->completed_at->toISOString() : '',
                    'cancelled_at' => $booking->cancelled_at ? $booking->cancelled_at->toISOString() : '',
                    'cancellation_reason' => $booking->cancellation_reason ?? '',
                    'cancelled_by_type' => $booking->cancelled_by_type ?? '',
                ],
                'locations' => [
                    'pickup' => [
                        'address' => $booking->pickup_address ?? '',
                        'latitude' => $booking->pickup_latitude ? (string) $booking->pickup_latitude : '',
                        'longitude' => $booking->pickup_longitude ? (string) $booking->pickup_longitude : '',
                        'zone' => $booking->pickupZone ? [
                            'id' => (string) $booking->pickupZone->id,
                            'name' => $booking->pickupZone->name ?? '',
                        ] : '',
                    ],
                    'dropoff' => [
                        'address' => $booking->dropoff_address ?? '',
                        'latitude' => $booking->dropoff_latitude ? (string) $booking->dropoff_latitude : '',
                        'longitude' => $booking->dropoff_longitude ? (string) $booking->dropoff_longitude : '',
                        'zone' => $booking->dropoffZone ? [
                            'id' => (string) $booking->dropoffZone->id,
                            'name' => $booking->dropoffZone->name ?? '',
                        ] : '',
                    ],
                ],
                'ride_details' => [
                    'ride_type' => $booking->rideType ? [
                        'id' => (string) $booking->rideType->id,
                        'name' => $booking->rideType->name ?? '',
                        'icon' => $booking->rideType->icon ?? '',
                        'description' => $booking->rideType->description ?? '',
                    ] : '',
                    'distance' => [
                        'estimated' => $booking->estimated_distance ? (string) $booking->estimated_distance : '',
                        'actual' => $booking->actual_distance ? (string) $booking->actual_distance : '',
                    ],
                    'duration' => [
                        'estimated' => $booking->estimated_duration ? (string) $booking->estimated_duration : '',
                        'actual' => $booking->actual_duration ? (string) $booking->actual_duration : '',
                    ],
                    'waiting_time' => $booking->waiting_time ? (string) $booking->waiting_time : '',
                ],
                'user_details' => [
                    'id' => (string) $booking->user->id,
                    'name' => $booking->user->name ?? '',
                    'email' => $booking->user->email ?? '',
                    'phone' => $booking->user->phone ?? '',
                    'profile_photo' => $booking->user->profile_photo ?? '',
                    'rating' => $booking->driver_rating ? (string) $booking->driver_rating : '',
                    'review' => $booking->driver_review ?? '',
                ],
                'driver_details' => $booking->driver ? [
                    'id' => (string) $booking->driver->id,
                    'name' => $booking->driver->name ?? '',
                    'email' => $booking->driver->email ?? '',
                    'phone' => $booking->driver->phone ?? '',
                    'profile_photo' => $booking->driver->profile_photo ?? '',
                    'rating' => $booking->driver->driverProfile ? (string) $booking->driver->driverProfile->rating : '',
                    'total_trips' => $booking->driver->driverProfile ? (string) $booking->driver->driverProfile->total_trips : '',
                    'completed_trips' => $booking->driver->driverProfile ? (string) $booking->driver->driverProfile->completed_trips : '',
                    'license_number' => $booking->driver->driverProfile ? $booking->driver->driverProfile->license_number ?? '' : '',
                    'license_expiry' => $booking->driver->driverProfile && $booking->driver->driverProfile->license_expiry ? $booking->driver->driverProfile->license_expiry->toDateString() : '',
                    'is_verified' => $booking->driver->driverProfile ? ($booking->driver->driverProfile->isVerified() ? '1' : '0') : '0',
                    'user_rating' => $booking->user_rating ? (string) $booking->user_rating : '',
                    'user_review' => $booking->user_review ?? '',
                ] : '',
                'vehicle_details' => $booking->driver && $booking->driver->vehicles->isNotEmpty() ? $booking->driver->vehicles->map(function ($vehicle) {
                    return [
                        'id' => (string) $vehicle->id,
                        'brand' => $vehicle->brand ?? '',
                        'model' => $vehicle->model ?? '',
                        'year' => $vehicle->year ? (string) $vehicle->year : '',
                        'color' => $vehicle->color ?? '',
                        'license_plate' => $vehicle->registration_number ?? '',
                        'registration_number' => $vehicle->registration_number ?? '',
                        'registration_expiry' => $vehicle->registration_expiry ? $vehicle->registration_expiry->toDateString() : '',
                        'insurance_expiry' => $vehicle->insurance_expiry ? $vehicle->insurance_expiry->toDateString() : '',
                        'status' => $vehicle->status ?? '',
                        'features' => $vehicle->features ?? [],
                        'ride_type' => $vehicle->rideType ? [
                            'id' => (string) $vehicle->rideType->id,
                            'name' => $vehicle->rideType->name ?? '',
                            'icon' => $vehicle->rideType->icon ?? '',
                        ] : '',
                    ];
                })->first() : null,
                'pricing' => [
                    'base_fare' => $booking->base_fare ? (string) $booking->base_fare : '',
                    'distance_fare' => $booking->distance_fare ? (string) $booking->distance_fare : '',
                    'time_fare' => $booking->time_fare ? (string) $booking->time_fare : '',
                    'waiting_charge' => $booking->waiting_charge ? (string) $booking->waiting_charge : '',
                    'cancellation_charge' => $booking->cancellation_charge ? (string) $booking->cancellation_charge : '',
                    'night_charge' => $booking->night_charge ? (string) $booking->night_charge : '',
                    'surge_multiplier' => $booking->surge_multiplier ? (string) $booking->surge_multiplier : '',
                    'surge_amount' => $booking->surge_amount ? (string) $booking->surge_amount : '',
                    'subtotal' => $booking->subtotal ? (string) $booking->subtotal : '',
                    'tax_rate' => $booking->tax_rate ? (string) $booking->tax_rate : '',
                    'tax_amount' => $booking->tax_amount ? (string) $booking->tax_amount : '',
                    'total_amount' => $booking->total_amount ? (string) $booking->total_amount : '',
                    'admin_commission_rate' => $booking->admin_commission_rate ? (string) $booking->admin_commission_rate : '',
                    'admin_commission' => $booking->admin_commission ? (string) $booking->admin_commission : '',
                    'platform_commission' => $booking->platform_commission ? (string) $booking->platform_commission : '',
                    'driver_amount' => $booking->driver_amount ? (string) $booking->driver_amount : '',
                    'promo_code' => $booking->promo_code ?? '',
                    'discount_amount' => $booking->discount_amount ? (string) $booking->discount_amount : '',
                    'wallet_amount' => $booking->wallet_amount ? (string) $booking->wallet_amount : '',
                    'online_paid_amount' => $booking->online_paid_amount ? (string) $booking->online_paid_amount : '',
                    'cash_amount' => $booking->cash_amount ? (string) $booking->cash_amount : '',
                    'estimated_fare' => $booking->estimated_fare ? (string) $booking->estimated_fare : '',
                    'final_fare' => $booking->final_fare ? (string) $booking->final_fare : '',
                ],
                'wallet_details' => $booking->user->wallet ? [
                    'id' => (string) $booking->user->wallet->id,
                    'balance' => (string) $booking->user->wallet->balance,
                    'total_credit' => (string) $booking->user->wallet->total_credit,
                    'total_debit' => (string) $booking->user->wallet->total_debit,
                    'status' => $booking->user->wallet->status ?? '',
                    'last_transaction_at' => $booking->user->wallet->last_transaction_at ? $booking->user->wallet->last_transaction_at->toISOString() : '',
                    'is_active' => $booking->user->wallet->isActive() ? '1' : '0',
                    'is_blocked' => $booking->user->wallet->isBlocked() ? '1' : '0',
                    'is_suspended' => $booking->user->wallet->isSuspended() ? '1' : '0',
                ] : '',
                'transactions' => $booking->transactions->map(function ($transaction) {
                    return [
                        'id' => (string) $transaction->id,
                        'transaction_id' => $transaction->transaction_id ?? '',
                        'type' => $transaction->type ?? '',
                        'amount' => (string) $transaction->amount,
                        'balance' => (string) $transaction->balance,
                        'description' => $transaction->description ?? '',
                        'status' => $transaction->status ?? '',
                        'payment_method' => $transaction->payment_method ?? '',
                        'currency' => $transaction->currency ?? '',
                        'gateway_transaction_id' => $transaction->gateway_transaction_id ?? '',
                        'processed_at' => $transaction->processed_at ? $transaction->processed_at->toISOString() : '',
                        'failed_at' => $transaction->failed_at ? $transaction->failed_at->toISOString() : '',
                        'created_at' => $transaction->created_at ? $transaction->created_at->toISOString() : '',
                    ];
                }),
                'timing_details' => [
                    'driver_arrival_time' => $booking->driver_arrival_time ? $booking->driver_arrival_time->toISOString() : '',
                    'pickup_time' => $booking->pickup_time ? $booking->pickup_time->toISOString() : '',
                    'dropoff_time' => $booking->dropoff_time ? $booking->dropoff_time->toISOString() : '',
                ],
                'additional_info' => [
                    'otp' => $booking->otp ?? '',
                    'is_confirm' => $booking->is_confirm ? '1' : '0',
                    'meta_data' => $booking->meta_data ?? [],
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'total_trips' => $trips->count(),
            'trips' => $trips,
        ]);
    }


    protected function updateDriverRating($driver): void
    {
        $averageRating = Booking::where('driver_id', $driver->id)
            ->whereNotNull('user_rating')
            ->avg('user_rating');

        $driver->driverProfile->update([
            'rating' => round($averageRating, 1),
        ]);
    }

    /**
     * Get billing distance (max of actual and estimated) to match fare calculation
     */
    private function getBillingDistance(Booking $booking): string
    {
        $billingDistance = max(
            (float) ($booking->actual_distance ?? 0),
            (float) ($booking->estimated_distance ?? 0)
        );

        return $billingDistance > 0 ? number_format($billingDistance, 2, '.', '') : '0.00';
    }

    /**
     * Get billing duration (max of actual and estimated) to match fare calculation
     */
    private function getBillingDuration(Booking $booking): string
    {
        $billingDuration = max(
            (int) ($booking->actual_duration ?? 0),
            (int) ($booking->estimated_duration ?? 0)
        );

        return $billingDuration > 0 ? (string) $billingDuration : '0';
    }
}
