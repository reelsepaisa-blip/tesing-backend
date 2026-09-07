<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MatanYadaev\EloquentSpatial\Objects\Polygon;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;

class Zone extends Model
{
    use HasFactory, SoftDeletes, HasSpatial, \App\Traits\PreventsDemoDeletion;

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($zone) {
            if ($zone->isDirty('status') && $zone->status == true) {
                $city = \App\Models\City::find($zone->city_id);

                if ($city && !$city->status) {
                    throw ValidationException::withMessages([
                        'status' => ['City is inactive. Cannot activate zone in an inactive city.'],
                        'is_active' => ['City is inactive. Cannot activate zone in an inactive city.'] // For forms using is_active
                    ]);
                }
            }
        });

        static::creating(function ($zone) {
            if ($zone->status == true) {
                $city = \App\Models\City::find($zone->city_id);

                if ($city && !$city->status) {
                    throw ValidationException::withMessages([
                        'status' => ['City is inactive. Cannot create active zone in an inactive city.'],
                        'is_active' => ['City is inactive. Cannot create active zone in an inactive city.'] // For forms using is_active
                    ]);
                }
            }
        });
    }

    protected $fillable = [
        'city_id',
        'name',
        'description',
        'boundaries', // Polygon
        'status',
        'surge_multiplier',
        'surge_start_time',
        'surge_end_time',
        'meta_data',
    ];

    protected $with = ['city'];

    protected $casts = [
        'boundaries' => Polygon::class,
        'status' => 'boolean',
        'surge_multiplier' => 'decimal:2',
        'surge_start_time' => 'datetime',
        'surge_end_time' => 'datetime',
        'meta_data' => 'array',
    ];

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function drivers()
    {
        return $this->hasMany(DriverLocation::class);
    }

    public function surgeSlots()
    {
        return $this->hasMany(ZoneSurgeSlot::class);
    }

    public function activeSurgeSlots()
    {
        return $this->hasMany(ZoneSurgeSlot::class)->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeContainsLocation($query, $latitude, $longitude)
    {
        return $query->whereRaw('ST_Contains(boundaries, POINT(?, ?))', [$longitude, $latitude]);
    }

    public function scopeIntersectsPath($query, array $points)
    {
        $linestring = new \MatanYadaev\EloquentSpatial\Objects\LineString(
            array_map(fn($point) => new Point($point['latitude'], $point['longitude']), $points)
        );
        return $query->intersects('boundaries', $linestring);
    }

    public function containsPoint(float $latitude, float $longitude): bool
    {
        $result = DB::select("SELECT ST_Contains(boundaries, POINT(?, ?)) as contains_point FROM zones WHERE id = ?", [$longitude, $latitude, $this->id]);
        return $result[0]->contains_point ?? false;
    }

    
    public function isWeeklySurgeActive(): bool
    {
        return $this->activeSurgeSlots()
            ->where('day_of_week', now()->dayOfWeek)
            ->where('start_time', '<=', now()->format('H:i:s'))
            ->where('end_time', '>=', now()->format('H:i:s'))
            ->exists();
    }

    
    public function getWeeklySurgeMultiplier(): float
    {
        $activeSlot = $this->activeSurgeSlots()
            ->where('day_of_week', now()->dayOfWeek)
            ->where('start_time', '<=', now()->format('H:i:s'))
            ->where('end_time', '>=', now()->format('H:i:s'))
            ->orderByDesc('surge_multiplier')
            ->first();

        return $activeSlot ? (float) $activeSlot->surge_multiplier : 1.0;
    }

    
    public function isDateSpecificSurgeActive(): bool
    {
        if (!$this->surge_multiplier || $this->surge_multiplier <= 1) {
            return false;
        }

        if (!$this->surge_start_time || !$this->surge_end_time) {
            return false;
        }

        $now = now();
        return $now->between($this->surge_start_time, $this->surge_end_time);
    }

    
    public function isSurgeActive(): bool
    {
        if ($this->isDateSpecificSurgeActive()) {
            return true;
        }

        return $this->isWeeklySurgeActive();
    }

    
    public function getCurrentMultiplier(): float
    {
        if ($this->isDateSpecificSurgeActive()) {
            return (float) $this->surge_multiplier;
        }

        $weeklyMultiplier = $this->getWeeklySurgeMultiplier();
        if ($weeklyMultiplier > 1.0) {
            return $weeklyMultiplier;
        }

        return 1.0;
    }

    public function getActiveDriversCount(): int
    {
        return $this->drivers()
            ->whereHas('driver', function ($query) {
                $query->where('is_online', true)
                    ->whereDoesntHave('bookingsAsDriver', function ($bookingQuery) {
                        $bookingQuery->whereIn('status', ['accepted', 'started']);
                    });
            })
            ->count();
    }

    public function shouldActivateSurge(): bool
    {
        $activeDrivers = $this->getActiveDriversCount();
        $pendingBookings = Booking::where('status', 'pending')
            ->whereHas('pickupZone', function ($query) {
                $query->where('id', $this->id);
            })
            ->count();

        return $pendingBookings > $activeDrivers;
    }

    public function updateSurgeStatus(): void
    {
        if ($this->shouldActivateSurge() && !$this->isSurgeActive()) {
            $this->update([
                'surge_multiplier' => 1.5, // Can be dynamic based on demand
                'surge_start_time' => now(),
                'surge_end_time' => now()->addHours(1),
            ]);
        } elseif (!$this->shouldActivateSurge() && $this->isSurgeActive()) {
            $this->update([
                'surge_multiplier' => 1.0,
                'surge_start_time' => null,
                'surge_end_time' => null,
            ]);
        }
    }
}
