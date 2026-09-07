<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IssueReport extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'driver_id',
        'user_id',
        'issue_type',
        'custom_issue',
        'description',
        'status',
        'priority',
        'reported_at',
        'resolved_at',
        'resolution_note',
        'meta_data',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
        'meta_data' => 'array',
    ];

    const ISSUE_TYPE_RIDER_DIDNT_SHOW_UP = 'rider_didnt_show_up';
    const ISSUE_TYPE_WRONG_PICKUP = 'wrong_pickup';
    const ISSUE_TYPE_RIDER_DELAYED = 'rider_delayed';
    const ISSUE_TYPE_TRAFFIC_ISSUE = 'traffic_issue';
    const ISSUE_TYPE_NAVIGATION_PROBLEM = 'navigation_problem';
    const ISSUE_TYPE_CUSTOM = 'custom';

    const STATUS_REPORTED = 'reported';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';

    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeByBooking($query, $bookingId)
    {
        return $query->where('booking_id', $bookingId);
    }

    public function scopeByDriver($query, $driverId)
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByIssueType($query, $issueType)
    {
        return $query->where('issue_type', $issueType);
    }

    public function getIssueTypeLabelAttribute()
    {
        $labels = [
            self::ISSUE_TYPE_RIDER_DIDNT_SHOW_UP => 'Rider Didn\'t Show Up',
            self::ISSUE_TYPE_WRONG_PICKUP => 'Wrong Pickup',
            self::ISSUE_TYPE_RIDER_DELAYED => 'Rider is Delayed',
            self::ISSUE_TYPE_TRAFFIC_ISSUE => 'Traffic Issue',
            self::ISSUE_TYPE_NAVIGATION_PROBLEM => 'Navigation Problem',
            self::ISSUE_TYPE_CUSTOM => 'Custom Issue',
        ];

        return $labels[$this->issue_type] ?? 'Unknown';
    }

    public function getStatusLabelAttribute()
    {
        $labels = [
            self::STATUS_REPORTED => 'Reported',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_CLOSED => 'Closed',
        ];

        return $labels[$this->status] ?? 'Unknown';
    }

    public function getPriorityLabelAttribute()
    {
        $labels = [
            self::PRIORITY_LOW => 'Low',
            self::PRIORITY_MEDIUM => 'Medium',
            self::PRIORITY_HIGH => 'High',
            self::PRIORITY_URGENT => 'Urgent',
        ];

        return $labels[$this->priority] ?? 'Unknown';
    }

    public function isResolved()
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function isOpen()
    {
        return in_array($this->status, [self::STATUS_REPORTED, self::STATUS_IN_PROGRESS]);
    }

    public function getDisplayIssue()
    {
        return $this->issue_type === self::ISSUE_TYPE_CUSTOM
            ? $this->custom_issue
            : $this->issue_type_label;
    }
}
