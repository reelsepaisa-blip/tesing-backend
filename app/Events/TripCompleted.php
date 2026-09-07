<?php

namespace App\Events;

use App\Models\Booking;
use Exception;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('user.all'),
            new Channel('drivers.all'),

            new PrivateChannel('user.' . $this->booking->user_id),
            new PrivateChannel('driver.' . $this->booking->driver_id),

            new PresenceChannel('trip.' . $this->booking->id),
            new PresenceChannel('booking.' . $this->booking->id),

            new Channel('public-booking.' . $this->booking->id),
            new Channel('public-trip.' . $this->booking->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'trip-completed';
    }

    public function broadcastWith(): array
    {
        $booking = $this->booking->load(['user', 'rideType', 'driver.driverProfile', 'driver.vehicles']);

        $fare = $this->calculateFareBreakdown($booking);

        $invoice = $this->generateInvoice($booking);

        $broadcastData = [
            'success' => true,
            'message' => 'Trip completed successfully',
            'booking' => $this->formatBookingData($booking),
            'fare' => $this->formatFareData($fare),
            'invoice' => $this->formatInvoiceData($invoice),
        ];

        return $broadcastData;
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
            'cancellation_charge' => (string) ($booking->cancellation_charge ?? ''),
            'night_charge' => (string) ($booking->night_charge ?? ''),
            'surge_multiplier' => (string) ($booking->surge_multiplier ?? ''),
            'surge_amount' => (string) ($booking->surge_amount ?? ''),
            'subtotal' => (string) ($booking->subtotal ?? ''),
            'tax_rate' => (string) ($booking->tax_rate ?? ''),
            'tax_amount' => (string) ($booking->tax_amount ?? ''),
            'total_amount' => (string) ($booking->total_amount ?? ''),
            'estimated_fare' => (string) ($booking->estimated_fare ?? ''),
            'final_fare' => (string) ($booking->final_fare ?? ''),
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
                'meta_data' => '',
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
            'meta_data' => $rideType->meta_data ?? '',
            'created_at' => $rideType->created_at ? $rideType->created_at->toISOString() : '',
            'updated_at' => $rideType->updated_at ? $rideType->updated_at->toISOString() : '',
            'deleted_at' => $rideType->deleted_at ? $rideType->deleted_at->toISOString() : '',
        ];
    }

    
    private function calculateFareBreakdown($booking): array
    {
        return [
            'base_fare' => (string) ($booking->base_fare ?? ''),
            'distance_fare' => (string) ($booking->distance_fare ?? ''),
            'time_fare' => (string) ($booking->time_fare ?? ''),
            'waiting_charge' => (string) ($booking->waiting_charge ?? ''),
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
                'name' => $booking->user->name ?? '',
                'phone' => $booking->user->phone ?? '',
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
                'night_charge' => (string) ($booking->night_charge ?? ''),
                'surge_amount' => (string) ($booking->surge_amount ?? ''),
                'subtotal' => (string) ($booking->subtotal ?? ''),
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
                'night_charge' => $invoice['fare_breakdown']['night_charge'] ?? '',
                'surge_amount' => $invoice['fare_breakdown']['surge_amount'] ?? '',
                'subtotal' => $invoice['fare_breakdown']['subtotal'] ?? '',
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
}
