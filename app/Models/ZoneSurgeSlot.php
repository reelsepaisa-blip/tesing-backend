<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZoneSurgeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'zone_id',
        'day_of_week',
        'start_time',
        'end_time',
        'surge_multiplier',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'surge_multiplier' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    
    public const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    
    public const DAY_SHORT_NAMES = [
        0 => 'Sun',
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDay($query, int $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek);
    }

    public function scopeForToday($query)
    {
        return $query->where('day_of_week', now()->dayOfWeek);
    }

    public function scopeActiveNow($query)
    {
        $now = now();
        $currentTime = $now->format('H:i:s');
        $currentDay = $now->dayOfWeek;

        return $query->active()
            ->forDay($currentDay)
            ->where('start_time', '<=', $currentTime)
            ->where('end_time', '>=', $currentTime);
    }


    
    public function getDayNameAttribute(): string
    {
        return self::DAY_NAMES[$this->day_of_week] ?? 'Unknown';
    }

    
    public function getDayShortNameAttribute(): string
    {
        return self::DAY_SHORT_NAMES[$this->day_of_week] ?? '?';
    }

    
    public function getFormattedStartTimeAttribute(): string
    {
        return Carbon::parse($this->start_time)->format('g:i A');
    }

    
    public function getFormattedEndTimeAttribute(): string
    {
        return Carbon::parse($this->end_time)->format('g:i A');
    }

    
    public function getTimeRangeAttribute(): string
    {
        return $this->formatted_start_time . ' - ' . $this->formatted_end_time;
    }

    
    public function isActiveNow(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();
        
        if ($now->dayOfWeek !== $this->day_of_week) {
            return false;
        }

        $currentTime = $now->format('H:i:s');
        $startTime = Carbon::parse($this->start_time)->format('H:i:s');
        $endTime = Carbon::parse($this->end_time)->format('H:i:s');

        if ($endTime < $startTime) {
            return $currentTime >= $startTime || $currentTime <= $endTime;
        }

        return $currentTime >= $startTime && $currentTime <= $endTime;
    }

    
    public function overlapsWithSlot(int $dayOfWeek, string $startTime, string $endTime, ?int $excludeId = null): bool
    {
        if ($this->day_of_week !== $dayOfWeek) {
            return false;
        }

        if ($excludeId && $this->id === $excludeId) {
            return false;
        }

        $thisStart = Carbon::parse($this->start_time);
        $thisEnd = Carbon::parse($this->end_time);
        $otherStart = Carbon::parse($startTime);
        $otherEnd = Carbon::parse($endTime);

        return !($thisEnd <= $otherStart || $thisStart >= $otherEnd);
    }

    
    public static function getActiveSlotsForZoneAndDay(int $zoneId, int $dayOfWeek): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('zone_id', $zoneId)
            ->active()
            ->forDay($dayOfWeek)
            ->orderBy('start_time')
            ->get();
    }

    
    public static function getCurrentSurgeMultiplierForZone(int $zoneId): float
    {
        $activeSlots = static::where('zone_id', $zoneId)
            ->activeNow()
            ->get();

        if ($activeSlots->isEmpty()) {
            return 1.0;
        }

        return (float) $activeSlots->max('surge_multiplier');
    }
}

