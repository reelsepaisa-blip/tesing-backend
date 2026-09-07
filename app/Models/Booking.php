<?php

namespace App\Models;

use App\Enums\BookingState;
use App\Events\BookingStatusChanged;
use App\Services\EnhancedFareCalculationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

class Booking extends Model
{
    use HasFactory, SoftDeletes, HasSpatial;

    public const DRIVER_PAYOUT_PENDING = 'pending';
    public const DRIVER_PAYOUT_SCHEDULED = 'scheduled';
    public const DRIVER_PAYOUT_COMPLETED = 'completed';

    protected $fillable = [
        'booking_code',
        'user_id',
        'driver_id',
        'passenger_name',
        'booking_contact_id',
        'is_other_booking',
        'city_id',
        'ride_type_id',
        'pickup_zone_id',
        'dropoff_zone_id',
        'pickup_location',
        'dropoff_location',
        'pickup_latitude',
        'pickup_longitude',
        'dropoff_latitude',
        'dropoff_longitude',
        'pickup_address',
        'dropoff_address',
        'status',
        'is_confirm',
        'payment_method',
        'payment_status',
        'distance',
        'duration',
        'estimated_distance',
        'estimated_duration',
        'actual_distance',
        'actual_duration',
        'base_fare',
        'distance_fare',
        'time_fare',
        'waiting_charge',
        'cancellation_charge',
        'night_charge',
        'surge_multiplier',
        'surge_amount',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total_amount',
        'admin_commission_rate',
        'admin_commission',
        'platform_commission',
        'driver_amount',
        'driver_payout_status',
        'driver_payout_scheduled_at',
        'driver_payout_released_at',
        'promo_usage_id',
        'promo_code',
        'discount_amount',
        'debt_amount',
        'tip_amount',
        'wallet_amount',
        'online_paid_amount',
        'cash_amount',
        'scheduled_at',
        'accepted_at',
        'arrived_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by_type',
        'cancelled_by_id',
        'user_rating',
        'user_review',
        'user_comment',
        'driver_rating',
        'driver_review',
        'driver_comment',
        'waiting_time',
        'reason',
        'otp',
        'trip_code',  // 4-digit OTP for ride verification
        'driver_arrival_time',
        'pickup_time',
        'dropoff_time',
        'total_waiting_time',
        'night_charges_multiplier',
        'night_charges_amount',
        'booking_fee',
        'promo_discount',
        'estimated_fare',
        'final_fare',
        'meta_data',
    ];

    protected $casts = [
        'is_confirm' => 'boolean',
        'is_other_booking' => 'boolean',
        'estimated_distance' => 'decimal:2',
        'estimated_duration' => 'integer',
        'actual_distance' => 'decimal:2',
        'actual_duration' => 'integer',
        'base_fare' => 'decimal:2',
        'distance_fare' => 'decimal:2',
        'time_fare' => 'decimal:2',
        'waiting_charge' => 'decimal:2',
        'cancellation_charge' => 'decimal:2',
        'night_charge' => 'decimal:2',
        'surge_multiplier' => 'decimal:2',
        'surge_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'admin_commission_rate' => 'decimal:2',
        'admin_commission' => 'decimal:2',
        'driver_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'debt_amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'wallet_amount' => 'decimal:2',
        'online_paid_amount' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'scheduled_at' => 'datetime',
        'accepted_at' => 'datetime',
        'arrived_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'user_rating' => 'decimal:1',
        'driver_rating' => 'decimal:1',
        'waiting_time' => 'integer',
        'driver_arrival_time' => 'datetime',
        'pickup_time' => 'datetime',
        'dropoff_time' => 'datetime',
        'total_waiting_time' => 'integer',
        'driver_payout_scheduled_at' => 'datetime',
        'driver_payout_released_at' => 'datetime',
        'night_charges_multiplier' => 'decimal:2',
        'night_charges_amount' => 'decimal:2',
        'booking_fee' => 'decimal:2',
        'promo_discount' => 'decimal:2',
        'estimated_fare' => 'decimal:2',
        'final_fare' => 'decimal:2',
        'meta_data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function rideType()
    {
        return $this->belongsTo(RideType::class);
    }

    public function pickupZone()
    {
        return $this->belongsTo(Zone::class, 'pickup_zone_id');
    }

    public function dropoffZone()
    {
        return $this->belongsTo(Zone::class, 'dropoff_zone_id');
    }

    public function cancelledBy()
    {
        return $this->morphTo();
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function driverLocations()
    {
        return $this->hasMany(DriverLocation::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method', 'code');
    }

    public function getPaymentMethodIdAttribute()
    {
        if (!$this->payment_method) {
            return '';
        }

        $paymentMethod = PaymentMethod::where('code', $this->payment_method)->first();
        return $paymentMethod ? (string) $paymentMethod->id : '';
    }

    public function promoUsage()
    {
        return $this->belongsTo(PromoUsage::class);
    }

    public function supportMessages()
    {
        return $this->hasMany(SupportMessage::class);
    }

    public function chats()
    {
        return $this->hasMany(Chat::class);
    }

    public function supportChats()
    {
        return $this->hasMany(SupportChat::class);
    }

    public function refundRequests()
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function latestRefundRequest()
    {
        return $this->hasOne(RefundRequest::class)->latestOfMany();
    }

    public function bookingContact()
    {
        return $this->belongsTo(BookingContact::class, 'booking_contact_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeStarted($query)
    {
        return $query->where('status', 'started');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeScheduled($query)
    {
        return $query
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now());
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'pending');
    }

    public function generateBookingCode(): string
    {
        $prefix = 'BK';
        $timestamp = now()->format('ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}{$timestamp}{$random}";
    }

    public function generateOTP(): string
    {
        return (string) random_int(1000, 9999);
    }

    public function calculateFare(): array
    {
        // Use maximum of actual and estimated for minimum billing
        $billingDistance = max(
            (float) ($this->actual_distance ?? 0),
            (float) ($this->estimated_distance ?? 0)
        );

        // Ensure minimum distance of 1 km if distance is less than 0
        if ($billingDistance < 0) {
            $billingDistance = 1.0;
        }

        $billingDuration = max(
            (int) ($this->actual_duration ?? 0),
            (int) ($this->estimated_duration ?? 0)
        );



        if ($this->status === 'cancelled') {
            return $this->calculateCancellationFare();
        }

        $city = $this->pickupZone->city;
        $pricing = $this->rideType->getPriceForCity($city);
        $multiplier = $city->getFareMultiplier() * ($this->surge_multiplier ?? 1);



        $this->base_fare = $pricing['base_price'];

        // Use billing distance (max of actual and estimated) for fare calculation
        $extraDistance = max(0, $billingDistance - $pricing['base_distance']);
        $this->distance_fare = $extraDistance * $pricing['price_per_km'];


        // Use billing duration (max of actual and estimated) for fare calculation
        $chargeableDuration = max(0, $billingDuration);
        $this->time_fare = $chargeableDuration * $pricing['price_per_minute'];


        $this->waiting_charge = 0;
        if ($this->waiting_time > $pricing['waiting_time_limit']) {
            $extraWaitingTime = $this->waiting_time - $pricing['waiting_time_limit'];
            $this->waiting_charge = $extraWaitingTime * $pricing['waiting_charge_per_minute'];
        }


        $baseSubtotal = $this->base_fare + $this->distance_fare + $this->time_fare + $this->waiting_charge;

        // If minimum fare applies, adjust base_fare to make breakdown add up correctly
        if ($baseSubtotal < $pricing['minimum_fare']) {
            $minimumFareAdjustment = $pricing['minimum_fare'] - $baseSubtotal;
            $this->base_fare = $this->base_fare + $minimumFareAdjustment;



            // Recalculate baseSubtotal with adjusted base_fare
            $baseSubtotal = $this->base_fare + $this->distance_fare + $this->time_fare + $this->waiting_charge;
        }

        $subtotalBeforeCharges = max($baseSubtotal, $pricing['minimum_fare']);

        $nightChargeMultiplier = $city->getFareMultiplier();
        if ($nightChargeMultiplier > 1) {
            $this->night_charge = $subtotalBeforeCharges * ($nightChargeMultiplier - 1);
        } else {
            $this->night_charge = 0;
        }

        $baseBeforeSurge = $subtotalBeforeCharges + $this->night_charge;

        if ($this->surge_multiplier > 1) {
            $this->subtotal = $baseBeforeSurge * $this->surge_multiplier;
            $this->surge_amount = $this->subtotal - $baseBeforeSurge;
        } else {
            $this->subtotal = $baseBeforeSurge;
            $this->surge_amount = 0;
        }


        // Store original subtotal before discount for display purposes
        $originalSubtotal = $this->subtotal;
        $actualDiscount = 0;

        if ($this->promo_code) {
            $promoCode = null;

            // Try to get promo code from promo_usage if available
            if ($this->promo_usage_id) {
                $promoUsage = $this->promoUsage;
                if ($promoUsage && $promoUsage->promoCode) {
                    $promoCode = $promoUsage->promoCode;
                }
            }

            // If not found, try to get promo code directly from database
            if (!$promoCode) {
                $promoCode = \App\Models\PromoCode::where('code', $this->promo_code)->first();
            }

            // Recalculate discount based on actual subtotal
            if ($promoCode && $originalSubtotal > 0) {
                $recalculatedDiscount = $promoCode->calculateDiscount($originalSubtotal);
                $actualDiscount = min($recalculatedDiscount, $originalSubtotal);



                // Update or create promo usage record
                if ($this->promo_usage_id) {
                    $promoUsage = $this->promoUsage;
                    if ($promoUsage) {
                        $promoUsage->update([
                            'original_amount' => $originalSubtotal,
                            'discount_amount' => $actualDiscount,
                            'final_amount' => $originalSubtotal - $actualDiscount,
                        ]);
                    }
                } else {
                    // Create promo usage record if it doesn't exist
                    $promoUsage = \App\Models\PromoUsage::updateOrCreate(
                        ['booking_id' => $this->id],
                        [
                            'promo_code_id' => $promoCode->id,
                            'user_id' => $this->user_id,
                            'original_amount' => $originalSubtotal,
                            'discount_amount' => $actualDiscount,
                            'final_amount' => $originalSubtotal - $actualDiscount,
                            'meta_data' => [
                                'promo_type' => $promoCode->type,
                                'promo_value' => $promoCode->value,
                                'is_referral' => $promoCode->is_referral_code ?? false,
                            ],
                        ]
                    );
                    $this->promo_usage_id = $promoUsage->id;
                }
            } else {
                // Fallback: use existing discount_amount if promo code not found, but cap it at subtotal
                $actualDiscount = min($this->discount_amount ?? 0, $originalSubtotal);
            }

            $this->discount_amount = $actualDiscount;
        } else {
            $this->discount_amount = 0;
        }

        // Calculate subtotal after discount (for commission calculation)
        $subtotalAfterDiscount = max(0, $originalSubtotal - $actualDiscount);

        $debtAmount = 0;
        $appliableDebts = collect();
        $user = $this->relationLoaded('user') ? $this->user : $this->user()->first();

        if ($user) {
            $openDebts = $user
                ->debts()
                ->open()
                ->where(function ($query) {
                    $query
                        ->whereNull('applied_booking_id')
                        ->orWhere('applied_booking_id', $this->id);
                })
                ->get();

            if ($openDebts->isNotEmpty()) {
                $debtAmount = $openDebts->sum(function (UserDebt $debt) {
                    return $debt->remaining_amount;
                });

                $appliableDebts = $openDebts->filter(function (UserDebt $debt) {
                    return $debt->status === UserDebt::STATUS_PENDING;
                });
            }
        }

        $this->debt_amount = $debtAmount;

        // Keep original subtotal (before discount) for display
        $this->subtotal = $originalSubtotal;

        // Calculate commission on subtotal after discount (BEFORE adding debt)
        // Commission should be on the actual fare amount, not including debt or tax
        $rideTypeCommissionRate = $pricing['commission_rate'];

        if (!$this->relationLoaded('driver')) {
            $this->load('driver.driverProfile');
        }

        $driverCommissionRate = null;
        if ($this->driver && $this->driver->driverProfile) {
            $driverCommissionRate = $this->driver->driverProfile->commission_rate;
        }

        $this->admin_commission_rate = $rideTypeCommissionRate ?? $driverCommissionRate;

        $this->admin_commission_rate = max(0, min(100, $this->admin_commission_rate));

        // Calculate commission on subtotal after discount (before debt and tax)
        $this->admin_commission = $subtotalAfterDiscount * ($this->admin_commission_rate / 100);

        $baseDriverAmount = $subtotalAfterDiscount - $this->admin_commission;
        $tipAmount = (float) ($this->tip_amount ?? 0);
        $this->driver_amount = $baseDriverAmount + $tipAmount;

        // Add debt to subtotal after discount (debt is added after discount and commission calculation)
        // Debt is added for tax calculation purposes
        $subtotalAfterDiscountAndDebt = $subtotalAfterDiscount;
        if ($debtAmount > 0) {
            $subtotalAfterDiscountAndDebt += $debtAmount;
        }

        $taxService = app(EnhancedFareCalculationService::class);

        // Calculate tax on the subtotal after discount + debt
        $taxData = $taxService->calculateTaxes($city, $subtotalAfterDiscountAndDebt);

        $this->tax_rate = $taxData['total_tax_rate'];
        $this->tax_amount = $taxData['total_tax_amount'];

        $baseTotal = $subtotalAfterDiscountAndDebt + $this->tax_amount;

        // baseTotal now represents the final fare before tips (subtotal after discount + debt + tax)
        $finalFareValue = $baseTotal;
        $this->total_amount = $finalFareValue + $tipAmount;

        $this->final_fare = $this->total_amount;




        $this->save();

        if ($appliableDebts->isNotEmpty()) {
            $appliableDebts->each(function (UserDebt $debt) {
                $debt->markApplied($this);
            });
        }

        return [
            'base_fare' => $this->base_fare,
            'distance_fare' => $this->distance_fare,
            'time_fare' => $this->time_fare,
            'waiting_charge' => $this->waiting_charge,
            'night_charge' => $this->night_charge,
            'surge_amount' => $this->surge_amount,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'debt_amount' => $this->debt_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'admin_commission' => $this->admin_commission,
            'driver_amount' => $this->driver_amount,
        ];
    }

    protected function calculateCancellationFare(): array
    {
        $city = $this->pickupZone->city;
        $status = $this->status instanceof BookingState
            ? $this->status
            : BookingState::from($this->status);

        $shouldApplyCharge = in_array($status, [
            BookingState::ACCEPTED,
            BookingState::ARRIVED,
            BookingState::STARTED,
        ], true);

        $chargeData = ['charge' => 0];

        if ($shouldApplyCharge) {
            // Free cancellation window should start from when driver accepts the ride
            // Only use accepted_at, not created_at as fallback
            $referenceTime = $this->accepted_at;
            $bookingDuration = 0;

            if ($referenceTime) {
                // Ensure accepted_at is a Carbon instance (handle case where it might be a string)
                if (!($referenceTime instanceof \Carbon\Carbon)) {
                    try {
                        $referenceTime = \Carbon\Carbon::parse($referenceTime);
                    } catch (\Exception $e) {
                        $referenceTime = null;
                    }
                }

                if ($referenceTime instanceof \Carbon\Carbon) {
                    $bookingDuration = (int) ceil(
                        $referenceTime->diffInMilliseconds(now()) / 1000
                    );
                }
            } else {
                // If accepted_at is null but status is ACCEPTED/ARRIVED/STARTED,
                // set duration to 0 which will be treated as within free window

            }

            $tripAmount = $this->subtotal ?? 0;

            $fareService = app(\App\Services\FareService::class);
            $chargeData = $fareService->getCancellationCharge([
                'ride_type_id' => $this->ride_type_id,
                'city_id' => $this->city_id,
                'booking_duration' => $bookingDuration,
                'trip_amount' => $tripAmount,
                'booking_status' => $status->value,
            ]);
        } else {
            // No charge applied
        }

        $this->cancellation_charge = $chargeData['charge'] ?? 0;
        $this->subtotal = $this->cancellation_charge;

        $taxService = app(EnhancedFareCalculationService::class);
        $taxData = $taxService->calculateTaxes($city, $this->subtotal);

        $this->tax_rate = $taxData['total_tax_rate'];
        $this->tax_amount = $taxData['total_tax_amount'];
        $this->total_amount = $this->subtotal + $this->tax_amount;
        $this->debt_amount = $this->total_amount;

        $pricing = $this->rideType->getPriceForCity($city);
        $this->admin_commission_rate = $pricing['commission_rate'];
        $this->admin_commission = $this->total_amount * ($this->admin_commission_rate / 100);
        $this->driver_amount = $this->total_amount - $this->admin_commission;

        $this->save();

        return [
            'cancellation_charge' => $this->cancellation_charge,
            'subtotal' => $this->subtotal,
            'debt_amount' => $this->debt_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'admin_commission' => $this->admin_commission,
            'driver_amount' => $this->driver_amount,
        ];
    }

    public function canBeCancelledByUser(): bool
    {
        if (!in_array($this->status, ['pending', 'accepted'])) {
            return false;
        }

        if ($this->status === 'accepted') {
            return $this->started_at === null;
        }

        return true;
    }

    public function canBeCancelledByDriver(): bool
    {
        return in_array($this->status, ['accepted', 'arrived', 'searching']) && $this->started_at === null;
    }

    public function isScheduled(): bool
    {
        return $this->scheduled_at !== null && $this->scheduled_at->isFuture();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isStarted(): bool
    {
        return $this->status === 'started';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    protected static function boot()
    {
        parent::boot();


        static::creating(function ($booking) {
            if (!$booking->booking_code) {
                $booking->booking_code = $booking->generateBookingCode();
            }

            if (!$booking->otp) {
                $booking->otp = $booking->generateOTP();
            }
        });
    }
}
