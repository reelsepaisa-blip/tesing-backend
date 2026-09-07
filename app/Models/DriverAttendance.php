<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DriverAttendance extends Model
{
    use HasFactory;

    protected $table = 'driver_attendance';

    protected $fillable = [
        'driver_id',
        'online_time',
        'offline_time',
        'total_online_seconds',
        'total_online_hours',
        'date',
        'meta_data',
    ];

    protected $casts = [
        'online_time' => 'datetime',
        'offline_time' => 'datetime',
        'total_online_seconds' => 'integer',
        'total_online_hours' => 'decimal:2',
        'date' => 'date',
        'meta_data' => 'array',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function scopeToday($query)
    {
        return $query->where('date', now()->toDateString());
    }

    public function scopeForDriver($query, $driverId)
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeOnline($query)
    {
        return $query->whereNull('offline_time');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('offline_time');
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function calculateOnlineTime()
    {
        if (!$this->offline_time) {
            $seconds = now()->timestamp - $this->online_time->timestamp;
            return max(0, $seconds); // Ensure non-negative
        }

        if ($this->total_online_seconds !== null) {
            return max(0, $this->total_online_seconds); // Ensure non-negative
        }

        $seconds = $this->offline_time->timestamp - $this->online_time->timestamp;
        return max(0, $seconds); // Ensure non-negative
    }

    public function getOnlineHours()
    {
        $seconds = abs($this->calculateOnlineTime()); // Use abs to avoid negative values
        return round($seconds / 3600, 2);
    }

    public function getFormattedDuration()
    {
        $seconds = abs($this->calculateOnlineTime()); // Use abs to avoid negative values
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    public function isActive()
    {
        return is_null($this->offline_time);
    }

    public function markOffline($offlineTime = null)
    {
        $offlineTime = $offlineTime ?: now();

        $originalOnlineTime = $this->online_time->copy();

        if ($offlineTime <= $originalOnlineTime) {
            $offlineTime = $originalOnlineTime->copy()->addSeconds(1); // Add 1 second minimum
        }

        $totalSeconds = $offlineTime->timestamp - $originalOnlineTime->timestamp;
        $totalSeconds = max(0, $totalSeconds); // Ensure non-negative

        \DB::statement("UPDATE driver_attendance SET 
                       online_time = '{$originalOnlineTime->format('Y-m-d H:i:s')}',
                       offline_time = '{$offlineTime->format('Y-m-d H:i:s')}', 
                       total_online_seconds = {$totalSeconds}, 
                       total_online_hours = " . round($totalSeconds / 3600, 2) . ", 
                       updated_at = '" . now()->format('Y-m-d H:i:s') . "' 
                       WHERE id = {$this->id}");

        $this->offline_time = $offlineTime;
        $this->total_online_seconds = $totalSeconds;
        $this->total_online_hours = round($totalSeconds / 3600, 2);
    }

    public static function getTodayTotalHours($driverId)
    {
        return static::forDriver($driverId)
            ->today()
            ->sum('total_online_hours') ?: 0;
    }

    public static function getTotalOnlineHours($driverId)
    {
        return static::forDriver($driverId)
            ->sum('total_online_hours') ?: 0;
    }

    public static function getCurrentOnlineSession($driverId)
    {
        return static::forDriver($driverId)
            ->online()
            ->latest('online_time')
            ->first();
    }

    public static function startOnlineSession($driverId, $metaData = [])
    {
        $existingSession = static::getCurrentOnlineSession($driverId);
        if ($existingSession) {
            $existingSession->markOffline();
        }

        return static::create([
            'driver_id' => $driverId,
            'online_time' => now(),
            'date' => now()->toDateString(),
            'meta_data' => $metaData,
        ]);
    }

    public static function endOnlineSession($driverId)
    {
        $session = static::getCurrentOnlineSession($driverId);
        if ($session) {
            $session->markOffline();
            return $session;
        }
        return null;
    }
}
