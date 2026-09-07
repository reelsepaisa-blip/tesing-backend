<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CancellationFeeDispute extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'user_id',
        'driver_id',
        'dispute_reason',
        'custom_reason',
        'description',
        'screenshot_path',
        'status',
        'admin_response',
        'resolved_at',
        'meta_data',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'meta_data' => 'array',
    ];

    const REASON_PASSENGER_DIDNT_SHOW_UP = 'passenger_didnt_show_up';
    const REASON_INCORRECT_FEE_CHARGED = 'incorrect_fee_charged';
    const REASON_ROUTE_BLOCKED_TRAFFIC = 'route_blocked_traffic';
    const REASON_WRONG_PICKUP_LOCATION = 'wrong_pickup_location';
    const REASON_NAVIGATION_APP_ERROR = 'navigation_app_error';
    const REASON_OTHER = 'other';

    const STATUS_PENDING = 'pending';
    const STATUS_UNDER_REVIEW = 'under_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_RESOLVED = 'resolved';

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function scopeByBooking($query, $bookingId)
    {
        return $query->where('booking_id', $bookingId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeUnderReview($query)
    {
        return $query->where('status', self::STATUS_UNDER_REVIEW);
    }

    public function scopeResolved($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RESOLVED]);
    }

    public function getDisputeReasonLabelAttribute()
    {
        $labels = [
            self::REASON_PASSENGER_DIDNT_SHOW_UP => 'Passenger didn\'t show up',
            self::REASON_INCORRECT_FEE_CHARGED => 'Incorrect Cancellation Fee Charged',
            self::REASON_ROUTE_BLOCKED_TRAFFIC => 'Route Blocked / Traffic Issues',
            self::REASON_WRONG_PICKUP_LOCATION => 'Rider Shared Wrong Pickup Location',
            self::REASON_NAVIGATION_APP_ERROR => 'Navigation or App Error',
            self::REASON_OTHER => 'Other',
        ];

        return $labels[$this->dispute_reason] ?? 'Unknown';
    }

    public function getStatusLabelAttribute()
    {
        $labels = [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_UNDER_REVIEW => 'Under Review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_RESOLVED => 'Resolved',
        ];

        return $labels[$this->status] ?? 'Unknown';
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isUnderReview()
    {
        return $this->status === self::STATUS_UNDER_REVIEW;
    }

    public function isResolved()
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RESOLVED]);
    }

    public function isApproved()
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected()
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function getDisplayReason()
    {
        return $this->dispute_reason === self::REASON_OTHER
            ? $this->custom_reason
            : $this->dispute_reason_label;
    }

    public function getScreenshotUrlAttribute()
    {
        if (!$this->screenshot_path) {
            return '';
        }

        return asset('storage/' . $this->screenshot_path);
    }

    public static function getDisputeReasons()
    {
        return [
            [
                'value' => self::REASON_PASSENGER_DIDNT_SHOW_UP,
                'label' => 'Passenger didn\'t show up',
                'description' => ''
            ],
            [
                'value' => self::REASON_INCORRECT_FEE_CHARGED,
                'label' => 'Incorrect Cancellation Fee Charged',
                'description' => ''
            ],
            [
                'value' => self::REASON_ROUTE_BLOCKED_TRAFFIC,
                'label' => 'Route Blocked / Traffic Issues',
                'description' => 'Unavoidable traffic or roadblock made me late, but I was on the way.'
            ],
            [
                'value' => self::REASON_WRONG_PICKUP_LOCATION,
                'label' => 'Rider Shared Wrong Pickup Location',
                'description' => ''
            ],
            [
                'value' => self::REASON_NAVIGATION_APP_ERROR,
                'label' => 'Navigation or App Error',
                'description' => ''
            ],
            [
                'value' => self::REASON_OTHER,
                'label' => 'Other',
                'description' => ''
            ],
        ];
    }
}

