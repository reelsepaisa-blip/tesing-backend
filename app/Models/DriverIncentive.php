<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class DriverIncentive extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'driver_id',
        'title',
        'description',
        'type',
        'criteria',
        'reward_amount',
        'status',
        'start_time',
        'end_time',
        'milestones',
        'zones',
        'ride_types',
        'time_slots',
        'is_active',
        'meta_data',
    ];

    protected $casts = [
        'criteria' => 'array',
        'milestones' => 'array',
        'zones' => 'array',
        'ride_types' => 'array',
        'time_slots' => 'array',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_active' => 'boolean',
        'meta_data' => 'array',
        'reward_amount' => 'decimal:2',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function progress()
    {
        return $this->hasMany(DriverIncentiveProgress::class, 'incentive_id');
    }

    public function driverProgress($driverId)
    {
        return $this->hasOne(DriverIncentiveProgress::class, 'incentive_id')
            ->where('driver_id', $driverId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLive($query)
    {
        $now = Carbon::now();
        return $query->where('status', 'live')
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now);
    }

    public function scopeUpcoming($query)
    {
        $now = Carbon::now();
        return $query->where('status', 'upcoming')
            ->where('start_time', '>', $now);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForDriver($query, $driverId)
    {
        return $query->where(function ($q) use ($driverId) {
            $q->where('driver_id', $driverId)
                ->orWhereNull('driver_id'); // include global incentives
        });
    }

    public function scopeForZone($query, $zoneId)
    {
        return $query->whereJsonContains('zones', $zoneId);
    }

    public function scopeForRideType($query, $rideTypeId)
    {
        return $query->whereJsonContains('ride_types', $rideTypeId);
    }

    public function isLive(): bool
    {
        $now = Carbon::now();
        return $this->status === 'live'
            && $this->start_time <= $now
            && $this->end_time > $now;
    }

    public function isUpcoming(): bool
    {
        $now = Carbon::now();
        return $this->status === 'upcoming' && $this->start_time > $now;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isExpired(): bool
    {
        return Carbon::now() > $this->end_time;
    }

    public function getProgressForDriver($driverId)
    {
        return $this->driverProgress($driverId)->first();
    }

    public function calculateProgress($driverId)
    {
        $progress = $this->getProgressForDriver($driverId);
        if (!$progress) {
            return [
                'current_count' => 0,
                'target_count' => $this->criteria['target'] ?? 0,
                'progress_percentage' => 0,
                'milestones_achieved' => [],
                'total_earned' => 0,
                'is_completed' => false
            ];
        }

        $currentCount = $progress->current_progress['count'] ?? 0;
        $targetCount = $this->criteria['target'] ?? 0;
        $progressPercentage = $targetCount > 0 ? ($currentCount / $targetCount) * 100 : 0;

        return [
            'current_count' => $currentCount,
            'target_count' => $targetCount,
            'progress_percentage' => round($progressPercentage, 2),
            'milestones_achieved' => $progress->milestone_progress ?? [],
            'total_earned' => $progress->total_earned,
            'is_completed' => $progress->is_completed
        ];
    }

    public function updateStatus()
    {
        $now = Carbon::now();

        if ($this->start_time > $now) {
            $this->status = 'upcoming';
        } elseif ($this->start_time <= $now && $this->end_time > $now) {
            $this->status = 'live';
        } elseif ($this->end_time <= $now) {
            $this->status = 'expired';
        }

        $this->save();
    }

    public function getFormattedTimeRemaining()
    {
        $now = Carbon::now();

        if ($this->isUpcoming()) {
            return $this->start_time->diffForHumans($now);
        } elseif ($this->isLive()) {
            return $this->end_time->diffForHumans($now);
        }

        return null;
    }
}
