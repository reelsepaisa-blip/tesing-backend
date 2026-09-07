<?php

namespace App\Events;

use App\Models\IssueReport;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


class IssueReported implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $issueReport;
    public $booking;
    public $driver;
    public $user;

    public function __construct(IssueReport $issueReport)
    {
        $this->issueReport = $issueReport;
        $this->booking = $issueReport->booking;
        $this->driver = $issueReport->driver;
        $this->user = $issueReport->user;
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('user.' . $this->user->id),
            new PrivateChannel('driver.' . $this->driver->id),
            new Channel('user.all'),
            new Channel('driver.all'),
            new PresenceChannel('trip.' . $this->booking->id),
            new Channel('public-booking.' . $this->booking->id),
            new Channel('public-trip.' . $this->booking->id),
            new Channel('issue-reports.all'),
        ];


        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'issue.reported';
    }

    public function broadcastWith(): array
    {
        $booking = $this->booking->load(['user', 'rideType', 'driver.driverProfile', 'driver.vehicles']);
        $driver = $this->driver->load(['driverProfile', 'vehicles']);
        $user = $this->user;

        $driverToken = $driver->bearer_token ?? '';
        $userToken = $user->bearer_token ?? '';

        return [
            'success' => true,
            'type' => 'issue_reported',
            'message' => 'Issue reported for booking #' . $booking->booking_code,
            'issue_report_id' => (string) $this->issueReport->id,
            'booking_id' => (string) $booking->id,
            'issue_report' => [
                'id' => (string) $this->issueReport->id,
                'booking_id' => (string) $this->issueReport->booking_id,
                'driver_id' => (string) $this->issueReport->driver_id,
                'user_id' => (string) $this->issueReport->user_id,
                'issue_type' => $this->issueReport->issue_type,
                'custom_issue' => $this->issueReport->custom_issue ?? '',
                'description' => $this->issueReport->description ?? '',
                'status' => $this->issueReport->status,
                'priority' => $this->issueReport->priority,
                'issue_type_label' => $this->issueReport->issue_type_label,
                'status_label' => $this->issueReport->status_label,
                'priority_label' => $this->issueReport->priority_label,
                'display_issue' => $this->issueReport->getDisplayIssue(),
                'reported_at' => $this->issueReport->reported_at->toISOString(),
                'resolved_at' => $this->issueReport->resolved_at ? $this->issueReport->resolved_at->toISOString() : '',
                'resolution_note' => $this->issueReport->resolution_note ?? '',
                'meta_data' => $this->issueReport->meta_data ?? [],
            ],
            'booking' => [
                'id' => (string) $booking->id,
                'booking_code' => $booking->booking_code,
                'user_id' => (string) $booking->user_id,
                'driver_id' => (string) $booking->driver_id,
                'city_id' => (string) $booking->city_id,
                'ride_type_id' => (string) $booking->ride_type_id,
                'pickup_zone_id' => (string) $booking->pickup_zone_id,
                'dropoff_zone_id' => (string) $booking->dropoff_zone_id,
                'pickup_location' => $booking->pickup_location ?? '',
                'pickup_latitude' => (string) $booking->pickup_latitude,
                'pickup_longitude' => (string) $booking->pickup_longitude,
                'dropoff_location' => $booking->dropoff_location ?? '',
                'dropoff_latitude' => (string) $booking->dropoff_latitude,
                'dropoff_longitude' => (string) $booking->dropoff_longitude,
                'pickup_address' => $booking->pickup_address,
                'dropoff_address' => $booking->dropoff_address,
                'status' => $booking->status,
                'is_conform' => (string) $booking->is_confirm,
                'is_confirm' => (string) $booking->is_confirm,
                'payment_method' => $booking->payment_method ?? '',
                'payment_status' => $booking->payment_status,
                'estimated_distance' => (string) $booking->estimated_distance,
                'estimated_duration' => (string) $booking->estimated_duration,
                'distance' => (string) $booking->distance,
                'duration' => (string) $booking->duration,
                'actual_distance' => (string) $booking->actual_distance,
                'actual_duration' => (string) $booking->actual_duration,
                'base_fare' => (string) $booking->base_fare,
                'distance_fare' => (string) $booking->distance_fare,
                'time_fare' => (string) $booking->time_fare,
                'waiting_charge' => (string) $booking->waiting_charge,
                'cancellation_charge' => (string) $booking->cancellation_charge,
                'night_charge' => (string) $booking->night_charge,
                'surge_multiplier' => (string) $booking->surge_multiplier,
                'surge_amount' => (string) $booking->surge_amount,
                'subtotal' => (string) $booking->subtotal,
                'tax_rate' => (string) $booking->tax_rate,
                'tax_amount' => (string) $booking->tax_amount,
                'total_amount' => (string) $booking->total_amount,
                'estimated_fare' => (string) $booking->estimated_fare,
                'final_fare' => (string) $booking->final_fare,
                'admin_commission_rate' => (string) $booking->admin_commission_rate,
                'admin_commission' => (string) $booking->admin_commission,
                'driver_amount' => (string) $booking->driver_amount,
                'promo_code' => $booking->promo_code ?? '',
                'discount_amount' => (string) $booking->discount_amount,
                'wallet_amount' => (string) $booking->wallet_amount,
                'online_paid_amount' => (string) $booking->online_paid_amount,
                'cash_amount' => (string) $booking->cash_amount,
                'scheduled_at' => $booking->scheduled_at ? $booking->scheduled_at->toISOString() : '',
                'started_at' => $booking->started_at ? $booking->started_at->toISOString() : '',
                'completed_at' => $booking->completed_at ? $booking->completed_at->toISOString() : '',
                'cancelled_at' => $booking->cancelled_at ? $booking->cancelled_at->toISOString() : '',
                'cancellation_reason' => $booking->cancellation_reason ?? '',
                'cancelled_by_type' => $booking->cancelled_by_type ?? '',
                'cancelled_by_id' => (string) $booking->cancelled_by_id,
                'user_rating' => (string) $booking->user_rating,
                'user_review' => $booking->user_review ?? '',
                'user_comment' => $booking->user_comment ?? '',
                'driver_rating' => (string) $booking->driver_rating,
                'driver_review' => $booking->driver_review ?? '',
                'driver_comment' => $booking->driver_comment ?? '',
                'waiting_time' => (string) $booking->waiting_time,
                'otp' => (string) $booking->otp,
                'trip_code' => (string) $booking->trip_code,
                'meta_data' => $booking->meta_data ?? '',
                'created_at' => $booking->created_at->toISOString(),
                'updated_at' => $booking->updated_at->toISOString(),
                'auto_arriving_scheduled_at' => $booking->auto_arriving_scheduled_at ? $booking->auto_arriving_scheduled_at->toISOString() : '',
                'deleted_at' => $booking->deleted_at ? $booking->deleted_at->toISOString() : '',
                'wallet_transaction_id' => (string) $booking->wallet_transaction_id,
                'promo_usage_id' => (string) $booking->promo_usage_id,
                'user' => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email ?? '',
                    'google_id' => $user->google_id ?? '',
                    'apple_id' => $user->apple_id ?? '',
                    'gender' => $user->gender ?? '',
                    'phone' => $user->phone,
                    'country_code' => $user->country_code ?? '',
                    'date_of_birth' => $user->date_of_birth ?? '',
                    'password_reset_token' => $user->password_reset_token ?? '',
                    'password_reset_expires_at' => $user->password_reset_expires_at ? $user->password_reset_expires_at->toISOString() : '',
                    'role_id' => (string) $user->role_id,
                    'profile_photo' => $user->profile_photo ?? '',
                    'token_expires_at' => $user->token_expires_at ? $user->token_expires_at->toISOString() : '',
                    'is_online' => (string) $user->is_online,
                    'is_verified' => (string) $user->is_verified,
                    'verified_at' => $user->verified_at ? $user->verified_at->toISOString() : '',
                    'last_location_at' => $user->last_location_at ? $user->last_location_at->toISOString() : '',
                    'last_latitude' => (string) $user->last_latitude,
                    'last_longitude' => (string) $user->last_longitude,
                    'select_latitude' => (string) $user->select_latitude,
                    'select_longitude' => (string) $user->select_longitude,
                    'select_address' => $user->select_address ?? '',
                    'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toISOString() : '',
                    'phone_verified_at' => $user->phone_verified_at ? $user->phone_verified_at->toISOString() : '',
                    'status' => $user->status ?? '',
                    'is_register' => (string) $user->is_register,
                    'step_0' => (string) $user->step_0,
                    'step_1' => (string) $user->step_1,
                    'step_2' => (string) $user->step_2,
                    'step_3' => (string) $user->step_3,
                    'referral_code' => $user->referral_code ?? '',
                    'referred_by' => (string) $user->referred_by,
                    'meta_data' => $user->meta_data ?? '',
                    'created_at' => $user->created_at->toISOString(),
                    'updated_at' => $user->updated_at->toISOString(),
                    'deleted_at' => $user->deleted_at ? $user->deleted_at->toISOString() : '',
                    'current_booking_id' => (string) $user->current_booking_id,
                ],
                'ride_type' => $booking->rideType ? [
                    'id' => (string) $booking->rideType->id,
                    'name' => $booking->rideType->name,
                    'code' => $booking->rideType->code ?? '',
                    'description' => $booking->rideType->description ?? '',
                    'icon' => $booking->rideType->icon ?? '',
                    'capacity' => (string) $booking->rideType->capacity,
                    'status' => (string) $booking->rideType->status,
                    'order' => (string) $booking->rideType->order,
                    'base_distance' => (string) $booking->rideType->base_distance,
                    'base_price' => (string) $booking->rideType->base_price,
                    'price_per_km' => (string) $booking->rideType->price_per_km,
                    'price_per_minute' => (string) $booking->rideType->price_per_minute,
                    'minimum_fare' => (string) $booking->rideType->minimum_fare,
                    'cancellation_charge' => (string) $booking->rideType->cancellation_charge,
                    'waiting_charge_per_minute' => (string) $booking->rideType->waiting_charge_per_minute,
                    'waiting_time_limit' => (string) $booking->rideType->waiting_time_limit,
                    'commission_rate' => (string) $booking->rideType->commission_rate,
                    'driver_requirements' => $booking->rideType->driver_requirements ?? [],
                    'vehicle_requirements' => $booking->rideType->vehicle_requirements ?? [],
                    'meta_data' => $booking->rideType->meta_data ?? '',
                    'created_at' => $booking->rideType->created_at->toISOString(),
                    'updated_at' => $booking->rideType->updated_at->toISOString(),
                    'deleted_at' => $booking->rideType->deleted_at ? $booking->rideType->deleted_at->toISOString() : '',
                ] : null,
            ],
            'driver' => [
                'id' => (string) $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'email' => $driver->email ?? '',
                'country_code' => $driver->country_code ?? '',
                'is_online' => (string) $driver->is_online,
                'is_verified' => (string) $driver->is_verified,
                'bearer_token' => $driverToken,
                'device_token' => $driver->device_token ?? '',
                'profile_photo' => $driver->profile_photo ?? '',
                'rating' => (string) ($driver->driverProfile->rating ?? 0),
                'total_trips' => (string) ($driver->driverProfile->total_rides ?? 0),
                'vehicle' => $driver->vehicles->first() ? [
                    'model' => $driver->vehicles->first()->model ?? '',
                    'make' => $driver->vehicles->first()->make ?? '',
                    'year' => (string) $driver->vehicles->first()->year,
                    'color' => $driver->vehicles->first()->color ?? '',
                    'number_plate' => $driver->vehicles->first()->registration_number ?? '',
                    'license_plate' => $driver->vehicles->first()-> registration_number?? '',
                ] : [
                    'model' => '',
                    'make' => '',
                    'year' => '',
                    'color' => '',
                    'number_plate' => '',
                    'license_plate' => '',
                ],
                'last_latitude' => (string) $driver->last_latitude,
                'last_longitude' => (string) $driver->last_longitude,
                'last_location_at' => $driver->last_location_at ? $driver->last_location_at->toISOString() : '',
                'current_location' => [
                    'latitude' => (string) $driver->last_latitude,
                    'longitude' => (string) $driver->last_longitude,
                    'address' => $driver->select_address ?? '',
                    'heading' => (string) $driver->last_heading,
                    'speed' => (string) $driver->last_speed,
                ],
            ],
            'user' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email ?? '',
                'country_code' => $user->country_code ?? '',
                'is_verified' => (string) $user->is_verified,
                'bearer_token' => $userToken,
                'device_token' => $user->device_token ?? '',
                'profile_image' => $user->profile_image ?? '',
                'created_at' => $user->created_at->toISOString(),
                'updated_at' => $user->updated_at->toISOString(),
            ],
            'driver_auth_token' => $driverToken,
            'event_type' => 'issue_reported',
            'status' => 'issue_reported',
            'data' => [],
            'timestamp' => now()->toISOString(),
        ];
    }
}
