<?php

namespace App\Events;

use App\Models\City;
use App\Services\CityService;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

use Exception;

class NewBooking implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;
    public $user;
    public $driver;

    public function __construct(Booking $booking, User $user, ?User $driver = null)
    {
        $this->booking = $booking;
        $this->user = $user;
        $this->driver = $driver;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('drivers.city.' . $this->booking->city_id),
            new Channel('drivers.all'),
        ];

        if ($this->booking->ride_type_id) {
            $channels[] = new Channel('drivers.ride_type.' . $this->booking->ride_type_id);
        }


        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'new.ride.request';
    }

    public function broadcastWith(): array
    {
        $booking = $this->booking->load(['user', 'bookingContact', 'rideType', 'driver.driverProfile', 'driver.vehicles', 'promoUsage.promoCode']);

        $driverDistance = $this->calculateDriverDistance();
        $etaToCustomer = $this->calculateETAToCustomer($driverDistance);

        $driverAuthToken = '';
        if ($this->driver) {
            $driverAuthToken = (string) $this->driver->id;
        }

        $fare = $this->calculateFareBreakdown($booking);

        $invoice = $this->generateInvoice($booking);

        $customerRating = $this->calculateCustomerRating($booking->user);

        $broadcastData = [
            'success' => true,
            'message' => 'New ride request created',
            'booking_id' => (string) $booking->id,
            'passenger' => [
                'name' => $this->getPassengerName($booking),
                'phone' => $this->getPassengerPhone($booking),
                'photo' => $this->getPassengerPhoto($booking),
                'rating' => 0,  // Default rating
            ],
            'trip_details' => [
                'distance' => $booking->estimated_distance ?: $booking->distance ?: '0.00',
                'fare' => $booking->estimated_fare ?: $booking->final_fare ?: '0.00',
                'duration' => (string) ($booking->estimated_duration ?? $booking->duration ?? '0'),
                'duration' => (string) ($booking->estimated_duration ?? $booking->duration ?? 0),
            ],
            'acceptance_timer' => 30,  // 30 seconds to accept
            'booking' => $this->formatBookingData($booking),
            'fare' => $this->formatFareData($fare),
            'invoice' => $this->formatInvoiceData($invoice),
            'customer' => [
                'customer_name' => $this->getPassengerName($booking),
                'customer_photo' => $this->getPassengerPhoto($booking),
                'customer_rating' => $customerRating,
                'distance_to_customer' => $driverDistance,
                'eta_to_customer' => $etaToCustomer . ' minutes',
            ],
            'pickup' => [
                'address' => $booking->pickup_address ?? '',
                'latitude' => (string) ($booking->pickup_latitude ?? ''),
                'longitude' => (string) ($booking->pickup_longitude ?? ''),
            ],
            'dropoff' => [
                'address' => $booking->dropoff_address ?? '',
                'latitude' => (string) ($booking->dropoff_latitude ?? ''),
                'longitude' => (string) ($booking->dropoff_longitude ?? ''),
            ],
            'driver_auth_token' => $driverAuthToken,
            'event_type' => 'new_ride_request',
            'timestamp' => now()->toISOString(),
        ];

        return $broadcastData;
    }

    private function calculateDriverDistance(): float
    {
        $driver = $this->driver ?: $this->getDriverFromEvent();

        if (!$driver || !$driver->currentLocation) {
            return 0.0;
        }

        return $this->calculateHaversineDistance(
            (float) $driver->currentLocation->latitude,
            (float) $driver->currentLocation->longitude,
            (float) $this->booking->pickup_latitude,
            (float) $this->booking->pickup_longitude
        );
    }

    private function calculateETAToCustomer(float $distance): int
    {
        if ($distance <= 0) {
            return 0;
        }

        $averageSpeed = 25;  // km/h
        $etaHours = $distance / $averageSpeed;
        $etaMinutes = $etaHours * 60;

        return (int) round($etaMinutes);
    }

    private function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;  // Earth's radius in kilometers

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    private function getDriverFromEvent(): ?User
    {
        $drivers = User::query()
            ->drivers()
            ->active()
            ->whereHas('vehicles', function ($query) {
                $query
                    ->where('ride_type_id', $this->booking->ride_type_id)
                    ->where('status', 'active');
            })
            ->whereHas('currentLocation', function ($query) {
                $query
                    ->where('is_active', true)
                    ->where('recorded_at', '>=', now()->subHours(24));
            })
            ->with(['currentLocation'])
            ->get();

        $closestDriver = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($drivers as $driver) {
            if ($driver->currentLocation) {
                $distance = $this->calculateHaversineDistance(
                    (float) $driver->currentLocation->latitude,
                    (float) $driver->currentLocation->longitude,
                    (float) $this->booking->pickup_latitude,
                    (float) $this->booking->pickup_longitude
                );

                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $closestDriver = $driver;
                }
            }
        }

        return $closestDriver;
    }

    private function formatBookingData($booking): array
    {
        return [
            'id' => (string) ($booking->id ?? ''),
            'booking_code' => $booking->booking_code ?? '',
            'user_id' => (string) ($booking->user_id ?? ''),
            'driver_id' => (string) ($booking->driver_id ?? ''),
            'city_id' => (string) ($booking->city_id ?? ''),
            'ride_type_id' => (string) ($booking->ride_type_id ?? ''),
            'payment_method_id' => $booking->payment_method_id ?? '',
            'pickup_zone_id' => (string) ($booking->pickup_zone_id ?? ''),
            'dropoff_zone_id' => (string) ($booking->dropoff_zone_id ?? ''),
            'pickup_location' => $booking->pickup_location ?? '',
            'pickup_latitude' => (string) ($booking->pickup_latitude ?? ''),
            'pickup_longitude' => (string) ($booking->pickup_longitude ?? ''),
            'dropoff_location' => $booking->dropoff_location ?? '',
            'dropoff_latitude' => (string) ($booking->dropoff_latitude ?? ''),
            'dropoff_longitude' => (string) ($booking->dropoff_longitude ?? ''),
            'pickup_address' => $booking->pickup_address ?? '',
            'dropoff_address' => $booking->dropoff_address ?? '',
            'status' => $booking->status ?? '',
            'is_conform' => $booking->is_conform ?? '',
            'is_confirm' => (string) ($booking->is_confirm ?? ''),
            'payment_method' => $booking->payment_method ?? '',
            'payment_status' => $booking->payment_status ?? '',
            'estimated_distance' => (string) ($booking->estimated_distance ?? ''),
            'estimated_duration' => (string) ($booking->estimated_duration ?? ''),
            'distance' => (string) ($booking->distance ?? ''),
            'duration' => (string) ($booking->duration ?? ''),
            'actual_distance' => (string) ($booking->actual_distance ?? ''),
            'actual_duration' => (string) ($booking->actual_duration ?? ''),
            'base_fare' => (string) ($booking->base_fare ?? ''),
            'distance_fare' => (string) ($booking->distance_fare ?? ''),
            'time_fare' => (string) ($booking->time_fare ?? ''),
            'waiting_charge' => (string) ($booking->waiting_charge ?? ''),
            'cancel_charge' => $this->getCancellationCharge($booking),
            'night_charge' => (string) ($booking->night_charge ?? ''),
            'surge_multiplier' => (string) ($booking->surge_multiplier ?? ''),
            'surge_amount' => (string) ($booking->surge_amount ?? ''),
            'subtotal' => (string) ($booking->subtotal ?? ''),
            'tax_rate' => (string) ($booking->tax_rate ?? ''),
            'tax_amount' => (string) ($booking->tax_amount ?? ''),
            'total_amount' => (string) ($booking->total_amount ?? ''),
            'estimated_fare' => (string) ($booking->estimated_fare ?? ''),
            'final_fare' => (
                isset($booking->final_fare, $booking->discount_amount)
                ? (string) ($booking->final_fare - $booking->discount_amount)
                : ''
            ),
            'admin_commission_rate' => (string) ($booking->admin_commission_rate ?? ''),
            'admin_commission' => (string) ($booking->admin_commission ?? ''),
            'driver_amount' => (string) ($booking->driver_amount ?? ''),
            'promo_code' => $booking->promo_code ?? '',
            'discount_amount' => (string) ($booking->discount_amount ?? ''),
            'wallet_amount' => (string) ($booking->wallet_amount ?? ''),
            'online_paid_amount' => (string) ($booking->online_paid_amount ?? ''),
            'cash_amount' => (string) ($booking->cash_amount ?? ''),
            'scheduled_at' => $booking->scheduled_at ? $booking->scheduled_at->toISOString() : '',
            'started_at' => $booking->started_at ? $booking->started_at->toISOString() : '',
            'completed_at' => $booking->completed_at ? $booking->completed_at->toISOString() : '',
            'cancelled_at' => $booking->cancelled_at ? $booking->cancelled_at->toISOString() : '',
            'cancellation_reason' => $booking->cancellation_reason ?? '',
            'cancelled_by_type' => $booking->cancelled_by_type ?? '',
            'cancelled_by_id' => (string) ($booking->cancelled_by_id ?? ''),
            'user_rating' => (string) ($booking->user_rating ?? ''),
            'user_review' => $booking->user_review ?? '',
            'user_comment' => $booking->user_comment ?? '',
            'driver_rating' => (string) ($booking->driver_rating ?? ''),
            'driver_review' => $booking->driver_review ?? '',
            'driver_comment' => $booking->driver_comment ?? '',
            'waiting_time' => (string) ($booking->waiting_time ?? ''),
            'otp' => $booking->otp ?? '',
            'trip_code' => $booking->trip_code ?? '',
            'meta_data' => $booking->meta_data ?? '',
            'created_at' => $booking->created_at ? $booking->created_at->toISOString() : '',
            'updated_at' => $booking->updated_at ? $booking->updated_at->toISOString() : '',
            'auto_arriving_scheduled_at' => $booking->auto_arriving_scheduled_at ? $booking->auto_arriving_scheduled_at->toISOString() : '',
            'deleted_at' => $booking->deleted_at ? $booking->deleted_at->toISOString() : '',
            'wallet_transaction_id' => (string) ($booking->wallet_transaction_id ?? ''),
            'promo_usage_id' => (string) ($booking->promo_usage_id ?? ''),
            'user' => $this->formatUserData($booking->user),
            'ride_type' => $this->formatRideTypeData($booking->rideType),
        ];
    }

    private function formatUserData($user): array
    {
        if (!$user) {
            return [
                'id' => '',
                'name' => '',
                'email' => '',
                'google_id' => '',
                'apple_id' => '',
                'gender' => '',
                'phone' => '',
                'country_code' => '',
                'date_of_birth' => '',
                'password_reset_token' => '',
                'password_reset_expires_at' => '',
                'role_id' => '',
                'profile_photo' => '',
                'token_expires_at' => '',
                'is_online' => '',
                'is_verified' => '',
                'verified_at' => '',
                'last_location_at' => '',
                'last_latitude' => '',
                'last_longitude' => '',
                'select_latitude' => '',
                'select_longitude' => '',
                'select_address' => '',
                'email_verified_at' => '',
                'phone_verified_at' => '',
                'status' => '',
                'is_register' => '',
                'step_0' => '',
                'step_1' => '',
                'step_2' => '',
                'step_3' => '',
                'referral_code' => '',
                'referred_by' => '',
                'meta_data' => '',
                'created_at' => '',
                'updated_at' => '',
                'deleted_at' => '',
                'current_booking_id' => '',
            ];
        }

        return [
            'id' => (string) ($user->id ?? ''),
            'name' => $user->name ?? '',
            'email' => $user->email ?? '',
            'google_id' => $user->google_id ?? '',
            'apple_id' => $user->apple_id ?? '',
            'gender' => $user->gender ?? '',
            'phone' => $user->phone ?? '',
            'country_code' => $user->country_code ?? '',
            'date_of_birth' => $user->date_of_birth ?? '',
            'password_reset_token' => $user->password_reset_token ?? '',
            'password_reset_expires_at' => $user->password_reset_expires_at ? $user->password_reset_expires_at->toISOString() : '',
            'role_id' => (string) ($user->role_id ?? ''),
            'profile_photo' => $this->getProfilePhotoUrl($user->profile_photo ?? ''),
            'token_expires_at' => $user->token_expires_at ? $user->token_expires_at->toISOString() : '',
            'is_online' => (string) ($user->is_online ?? ''),
            'is_verified' => (string) ($user->is_verified ?? ''),
            'verified_at' => $user->verified_at ? $user->verified_at->toISOString() : '',
            'last_location_at' => $user->last_location_at ? $user->last_location_at->toISOString() : '',
            'last_latitude' => (string) ($user->last_latitude ?? ''),
            'last_longitude' => (string) ($user->last_longitude ?? ''),
            'select_latitude' => (string) ($user->select_latitude ?? ''),
            'select_longitude' => (string) ($user->select_longitude ?? ''),
            'select_address' => $user->select_address ?? '',
            'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toISOString() : '',
            'phone_verified_at' => $user->phone_verified_at ? $user->phone_verified_at->toISOString() : '',
            'status' => $user->status ?? '',
            'is_register' => (string) ($user->is_register ?? ''),
            'step_0' => (string) ($user->step_0 ?? ''),
            'step_1' => (string) ($user->step_1 ?? ''),
            'step_2' => (string) ($user->step_2 ?? ''),
            'step_3' => (string) ($user->step_3 ?? ''),
            'referral_code' => $user->referral_code ?? '',
            'referred_by' => (string) ($user->referred_by ?? ''),
            'meta_data' => $user->meta_data ?? '',
            'created_at' => $user->created_at ? $user->created_at->toISOString() : '',
            'updated_at' => $user->updated_at ? $user->updated_at->toISOString() : '',
            'deleted_at' => $user->deleted_at ? $user->deleted_at->toISOString() : '',
            'current_booking_id' => (string) ($user->current_booking_id ?? ''),
        ];
    }

    private function formatRideTypeData($rideType): array
    {
        if (!$rideType) {
            return [
                'id' => '',
                'name' => '',
                'code' => '',
                'description' => '',
                'icon' => '',
                'capacity' => '',
                'status' => '',
                'order' => '',
                'base_distance' => '',
                'base_price' => '',
                'price_per_km' => '',
                'price_per_minute' => '',
                'minimum_fare' => '',
                'cancellation_charge' => '',
                'waiting_charge_per_minute' => '',
                'waiting_time_limit' => '',
                'commission_rate' => '',
                'driver_requirements' => [],
                'vehicle_requirements' => [],
                'created_at' => '',
                'updated_at' => '',
                'deleted_at' => '',
            ];
        }

        return [
            'id' => (string) ($rideType->id ?? ''),
            'name' => $rideType->name ?? '',
            'code' => $rideType->code ?? '',
            'description' => $rideType->description ?? '',
            'icon' => $rideType->icon ?? '',
            'capacity' => (string) ($rideType->capacity ?? ''),
            'status' => (string) ($rideType->status ?? ''),
            'order' => (string) ($rideType->order ?? ''),
            'base_distance' => (string) ($rideType->base_distance ?? ''),
            'base_price' => (string) ($rideType->base_price ?? ''),
            'price_per_km' => (string) ($rideType->price_per_km ?? ''),
            'price_per_minute' => (string) ($rideType->price_per_minute ?? ''),
            'minimum_fare' => (string) ($rideType->minimum_fare ?? ''),
            'cancellation_charge' => (string) ($rideType->cancellation_charge ?? ''),
            'waiting_charge_per_minute' => (string) ($rideType->waiting_charge_per_minute ?? ''),
            'waiting_time_limit' => (string) ($rideType->waiting_time_limit ?? ''),
            'commission_rate' => (string) ($rideType->commission_rate ?? ''),
            'driver_requirements' => $rideType->driver_requirements ?? [],
            'vehicle_requirements' => $rideType->vehicle_requirements ?? [],
            'created_at' => $rideType->created_at ? $rideType->created_at->toISOString() : '',
            'updated_at' => $rideType->updated_at ? $rideType->updated_at->toISOString() : '',
            'deleted_at' => $rideType->deleted_at ? $rideType->deleted_at->toISOString() : '',
        ];
    }

    private function getCancellationCharge($booking): string
    {
        try {
            $city = City::find($booking->city_id);
            if ($city && $booking->ride_type_id) {
                $cityService = app(CityService::class);
                $policy = $cityService->getCancellationPolicy($city, $booking->ride_type_id);

                if ($policy) {
                    // Use cancellation policy fee (for new bookings, trip amount is 0, so just return fixed fee)
                    return (string) ($policy->cancellation_fee ?? '0');
                }
            }
        } catch (Exception $e) {
        }

        // Fallback to ride type's cancellation charge if policy not found
        return (string) ($booking->rideType->cancellation_charge ?? '0');
    }

    private function calculateFareBreakdown($booking): array
    {
        return [
            'base_fare' => (string) ($booking->base_fare ?? ''),
            'distance_fare' => (string) ($booking->distance_fare ?? ''),
            'time_fare' => (string) ($booking->time_fare ?? ''),
            'waiting_charge' => (string) ($booking->waiting_charge ?? ''),
            'cancellation_charge' => $this->getCancellationCharge($booking),
            'night_charge' => (string) ($booking->night_charge ?? ''),
            'surge_amount' => (string) ($booking->surge_amount ?? ''),
            'subtotal' => (string) ($booking->subtotal ?? ''),
            'discount_amount' => (string) ($booking->discount_amount ?? ''),
            'tax_amount' => (string) ($booking->tax_amount ?? ''),
            'total_amount' => (string) ($booking->total_amount ?? ''),
            'admin_commission' => (string) ($booking->admin_commission ?? ''),
            'driver_amount' => (string) ($booking->driver_amount ?? ''),
        ];
    }

    private function formatFareData($fare): array
    {
        return [
            'base_fare' => $fare['base_fare'] ?? '',
            'distance_fare' => $fare['distance_fare'] ?? '',
            'time_fare' => $fare['time_fare'] ?? '',
            'waiting_charge' => $fare['waiting_charge'] ?? '',
            'cancellation_charge' => $fare['cancellation_charge'] ?? '',
            'night_charge' => $fare['night_charge'] ?? '',
            'surge_amount' => $fare['surge_amount'] ?? '',
            'subtotal' => $fare['subtotal'] ?? '',
            'discount_amount' => $fare['discount_amount'] ?? '',
            'tax_amount' => $fare['tax_amount'] ?? '',
            'total_amount' => $fare['total_amount'] ?? '',
            'admin_commission' => $fare['admin_commission'] ?? '',
            'driver_amount' => $fare['driver_amount'] ?? '',
        ];
    }

    private function generateInvoice($booking): array
    {
        $driver = $booking->driver;
        $vehicle = $driver ? $driver->vehicles->first() : null;

        return [
            'invoice_number' => 'INV-' . date('Y') . '-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT),
            'booking_id' => (string) ($booking->id ?? ''),
            'booking_code' => $booking->booking_code ?? '',
            'invoice_date' => now()->format('Y-m-d H:i:s'),
            'customer' => [
                'name' => $this->getPassengerName($booking),
                'phone' => $this->getPassengerPhone($booking),
                'email' => $booking->user->email ?? '',
            ],
            'driver' => [
                'name' => $driver->name ?? '',
                'phone' => $driver->phone ?? '',
                'profile_photo' => $this->getProfilePhotoUrl($driver->profile_photo ?? ''),
                'vehicle' => $vehicle->model ?? '',
                'license_plate' => $vehicle->registration_number ?? '',
            ],
            'trip_details' => [
                'pickup_address' => $booking->pickup_address ?? '',
                'dropoff_address' => $booking->dropoff_address ?? '',
                'distance' => ($booking->distance ?? '0.00') . ' km',
                'duration' => ($booking->duration ?? '0') . ' minutes',
                'started_at' => $booking->started_at ? $booking->started_at->format('Y-m-d H:i:s') : '',
                'completed_at' => $booking->completed_at ? $booking->completed_at->format('Y-m-d H:i:s') : '',
            ],
            'fare_breakdown' => [
                'base_fare' => (string) ($booking->base_fare ?? ''),
                'distance_fare' => (string) ($booking->distance_fare ?? ''),
                'time_fare' => (string) ($booking->time_fare ?? ''),
                'waiting_charge' => (string) ($booking->waiting_charge ?? ''),
                'cancellation_charge' => $this->getCancellationCharge($booking),
                'night_charge' => (string) ($booking->night_charge ?? ''),
                'surge_amount' => (string) ($booking->surge_amount ?? ''),
                'subtotal' => (string) ($booking->subtotal ?? ''),
                'promo_code' => (string) ($booking->promo_code ?? ''),
                'promo_description' => $booking->promoUsage?->promoCode?->description ?? null,
                'discount_amount' => (string) ($booking->discount_amount ?? '0'),
                'tax_amount' => (string) ($booking->tax_amount ?? ''),
                'total_amount' => (string) ($booking->total_amount ?? ''),
            ],
            'payment_details' => [
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status ?? '',
                'driver_amount' => (string) ($booking->driver_amount ?? ''),
                'platform_commission' => (string) ($booking->admin_commission ?? ''),
                'driver_commission_rate' => $booking->rideType ? (100 - $booking->rideType->commission_rate) . '%' : '0%',
                'platform_commission_rate' => ($booking->rideType->commission_rate ?? '0') . '%',
            ],
        ];
    }

    private function formatInvoiceData($invoice): array
    {
        return [
            'invoice_number' => $invoice['invoice_number'] ?? '',
            'booking_id' => $invoice['booking_id'] ?? '',
            'booking_code' => $invoice['booking_code'] ?? '',
            'invoice_date' => $invoice['invoice_date'] ?? '',
            'customer' => [
                'name' => $invoice['customer']['name'] ?? '',
                'phone' => $invoice['customer']['phone'] ?? '',
                'email' => $invoice['customer']['email'] ?? '',
            ],
            'driver' => [
                'name' => $invoice['driver']['name'] ?? '',
                'phone' => $invoice['driver']['phone'] ?? '',
                'profile_photo' => $invoice['driver']['profile_photo'] ?? '',
                'vehicle' => $invoice['driver']['vehicle'] ?? '',
                'license_plate' => $invoice['driver']['registration_number'] ?? '',
                'registration_number' => $invoice['driver']['registration_number'] ?? '',
            ],
            'trip_details' => [
                'pickup_address' => $invoice['trip_details']['pickup_address'] ?? '',
                'dropoff_address' => $invoice['trip_details']['dropoff_address'] ?? '',
                'distance' => $invoice['trip_details']['distance'] ?? '',
                'duration' => $invoice['trip_details']['duration'] ?? '',
                'started_at' => $invoice['trip_details']['started_at'] ?? '',
                'completed_at' => $invoice['trip_details']['completed_at'] ?? '',
            ],
            'fare_breakdown' => [
                'base_fare' => $invoice['fare_breakdown']['base_fare'] ?? '',
                'distance_fare' => $invoice['fare_breakdown']['distance_fare'] ?? '',
                'time_fare' => $invoice['fare_breakdown']['time_fare'] ?? '',
                'waiting_charge' => $invoice['fare_breakdown']['waiting_charge'] ?? '',
                'cancellation_charge' => $invoice['fare_breakdown']['cancellation_charge'] ?? '',
                'night_charge' => $invoice['fare_breakdown']['night_charge'] ?? '',
                'surge_amount' => $invoice['fare_breakdown']['surge_amount'] ?? '',
                'subtotal' => $invoice['fare_breakdown']['subtotal'] ?? '',
                'promo_code' => $invoice['fare_breakdown']['promo_code'] ?? '',
                'promo_description' => $invoice['fare_breakdown']['promo_description'] ?? '',
                'discount_amount' => $invoice['fare_breakdown']['discount_amount'] ?? '',
                'tax_amount' => $invoice['fare_breakdown']['tax_amount'] ?? '',
                'total_amount' => $invoice['fare_breakdown']['total_amount'] ?? '',
            ],
            'payment_details' => [
                'payment_method' => $invoice['payment_details']['payment_method'] ?? '',
                'payment_status' => $invoice['payment_details']['payment_status'] ?? '',
                'driver_amount' => $invoice['payment_details']['driver_amount'] ?? '',
                'platform_commission' => $invoice['payment_details']['platform_commission'] ?? '',
                'driver_commission_rate' => $invoice['payment_details']['driver_commission_rate'] ?? '',
                'platform_commission_rate' => $invoice['payment_details']['platform_commission_rate'] ?? '',
            ],
        ];
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

    private function getPassengerName($booking): string
    {
        if ($booking->booking_contact_id !== null && $booking->bookingContact) {
            return $booking->bookingContact->name ?? '';
        }

        return $booking->user->name ?? '';
    }

    private function getPassengerPhone($booking): string
    {
        if ($booking->booking_contact_id !== null && $booking->bookingContact) {
            return $booking->bookingContact->mobile_number ?? '';
        }

        return $booking->user->phone ?? '';
    }

    private function getPassengerPhoto($booking): string
    {
        if ($booking->booking_contact_id !== null && $booking->bookingContact) {
            $profilePic = $booking->bookingContact->profile_pic ?? null;
            if ($profilePic) {
                return $this->getProfilePhotoUrl($profilePic);
            }
        }

        return $this->getProfilePhotoUrl($booking->user->profile_photo ?? '');
    }

    public function getFCMPayloadData(): array
    {
        $booking = $this->booking->load(['user', 'bookingContact', 'rideType', 'driver.driverProfile', 'driver.vehicles', 'promoUsage.promoCode']);

        $driverDistance = $this->calculateDriverDistance();
        $etaToCustomer = $this->calculateETAToCustomer($driverDistance);

        $driverAuthToken = '';
        if ($this->driver) {
            $driverAuthToken = (string) $this->driver->id;
        }

        $fare = $this->calculateFareBreakdown($booking);

        $invoice = $this->generateInvoice($booking);

        $driverData = $this->formatDriverDataForFCM($this->driver);

        $customerRating = $this->calculateCustomerRating($booking->user);

        $payloadData = [
            'booking_id' => (string) $booking->id,
            'passenger' => [
                'name' => $this->getPassengerName($booking),
                'phone' => $this->getPassengerPhone($booking),
                'photo' => $this->getPassengerPhoto($booking),
                'rating' => 0,  // Default rating
            ],
            'trip_details' => [
                'distance' => $booking->estimated_distance ?: $booking->distance ?: '0.00',
                'fare' => $booking->estimated_fare ?: $booking->final_fare ?: '0.00',
                'duration' => $booking->estimated_duration ?: $booking->duration ?: '0',
            ],
            'acceptance_timer' => 30,
            'booking' => $this->formatBookingData($booking),
            'fare' => $this->formatFareData($fare),
            'invoice' => $this->formatInvoiceData($invoice),
            'customer' => [
                'customer_name' => $this->getPassengerName($booking),
                'customer_photo' => $this->getPassengerPhoto($booking),
                'customer_rating' => $customerRating,
                'distance_to_customer' => $driverDistance,
                'eta_to_customer' => $etaToCustomer . ' minutes',
            ],
            'driver' => $driverData,
            'pickup' => [
                'address' => $booking->pickup_address ?? '',
                'latitude' => (string) ($booking->pickup_latitude ?? ''),
                'longitude' => (string) ($booking->pickup_longitude ?? ''),
            ],
            'dropoff' => [
                'address' => $booking->dropoff_address ?? '',
                'latitude' => (string) ($booking->dropoff_latitude ?? ''),
                'longitude' => (string) ($booking->dropoff_longitude ?? ''),
            ],
            'driver_auth_token' => $driverAuthToken,
            'event_type' => 'booking_status_changed',
            'status' => 'booking_confirmed',
            'data' => [],
            'timestamp' => now()->toISOString(),
        ];

        return $payloadData;
    }

    private function formatDriverDataForFCM(?User $driver): array
    {
        if (!$driver) {
            return [
                'id' => '',
                'name' => '',
                'phone' => '',
                'email' => '',
                'profile_photo' => '',
                'rating' => '0',
                'total_trips' => '0',
                'vehicle' => [
                    'model' => '',
                    'make' => '',
                    'year' => '',
                    'color' => '',
                    'number_plate' => '',
                    'license_plate' => '',
                ],
                'is_online' => '0',
                'last_latitude' => '',
                'last_longitude' => '',
                'last_location_at' => '',
                'current_location' => [
                    'latitude' => '',
                    'longitude' => '',
                    'address' => '',
                    'heading' => '',
                    'speed' => '',
                ],
            ];
        }

        if (!$driver->relationLoaded('vehicles')) {
            $driver->load('vehicles');
        }
        if (!$driver->relationLoaded('driverProfile')) {
            $driver->load('driverProfile');
        }
        if (!$driver->relationLoaded('currentLocation')) {
            $driver->load('currentLocation');
        }

        $vehicle = $driver->vehicles->first();

        $currentLocation = $driver->currentLocation;

        // Calculate total_trips dynamically from bookings count
        $totalTrips = $driver->bookingsAsDriver()->count();

        return [
            'id' => (string) ($driver->id ?? ''),
            'name' => $driver->name ?? '',
            'phone' => $driver->phone ?? '',
            'email' => $driver->email ?? '',
            'profile_photo' => $this->getProfilePhotoUrl($driver->profile_photo ?? ''),
            'rating' => (string) ($driver->driverProfile->rating ?? '0'),
            'total_trips' => (string) $totalTrips,
            'vehicle' => [
                'model' => $vehicle ? ($vehicle->model ?? '') : '',
                'make' => $vehicle ? ($vehicle->make ?? '') : '',
                'year' => $vehicle ? (string) ($vehicle->year ?? '') : '',
                'color' => $vehicle ? ($vehicle->color ?? '') : '',
                'number_plate' => $vehicle ? ($vehicle->registration_number ?? '') : '',
                'license_plate' => $vehicle ? ($vehicle->registration_number ?? '') : '',
            ],
            'is_online' => (string) ($driver->is_online ?? '0'),
            'last_latitude' => (string) ($driver->last_latitude ?? ''),
            'last_longitude' => (string) ($driver->last_longitude ?? ''),
            'last_location_at' => $driver->last_location_at ? $driver->last_location_at->toISOString() : '',
            'current_location' => [
                'latitude' => $currentLocation ? (string) ($currentLocation->latitude ?? '') : '',
                'longitude' => $currentLocation ? (string) ($currentLocation->longitude ?? '') : '',
                'address' => $currentLocation ? ($currentLocation->address ?? '') : '',
                'heading' => $currentLocation ? (string) ($currentLocation->heading ?? '') : '',
                'speed' => $currentLocation ? (string) ($currentLocation->speed ?? '') : '',
            ],
        ];
    }

    private function calculateCustomerRating($user): float
    {
        if (!$user) {
            return 0.0;
        }

        $averageRating = Booking::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereNotNull('driver_rating')
            ->where('driver_rating', '>', 0)
            ->avg('driver_rating');

        return round((float) ($averageRating ?? 0), 1);
    }
}
