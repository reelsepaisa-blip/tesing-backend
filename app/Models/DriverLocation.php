<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'zone_id',
        'latitude',
        'longitude',
        'location',
        'address',
        'heading',
        'is_active',
        'recorded_at',
        'updated_at',
        'accuracy',
        'speed',
        'battery_level',
        'is_charging',

    ];

    protected $casts = [
        'location' => 'string',
        'heading' => 'decimal:2',
        'speed' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'battery_level' => 'integer',
        'is_charging' => 'boolean',
        'is_active' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRecent($query)
    {
        return $query->where('recorded_at', '>=', now()->subMinutes(5));
    }

    public function scopeWithinRadius($query, $latitude, $longitude, $radius)
    {
        $haversine = "(
            6371 * acos(
                cos(radians(?)) * 
                cos(radians(latitude)) * 
                cos(radians(longitude) - radians(?)) + 
                sin(radians(?)) * 
                sin(radians(latitude))
            )
        )";

        return $query->whereRaw($haversine . " <= ?", [
            $latitude,
            $longitude,
            $latitude,
            ($radius / 1000) // Convert meters to kilometers
        ]);
    }

    public function updateZone(): void
    {
        $coordinates = $this->parseLocation();
        if ($coordinates) {
            $zone = Zone::active()
                ->containsLocation($coordinates['latitude'], $coordinates['longitude'])
                ->first();

            if ($this->zone_id !== optional($zone)->id) {
                $this->update(['zone_id' => $zone?->id]);
            }
        }
    }

    public function distanceTo($latitude, $longitude): float
    {
        if (!$this->latitude || !$this->longitude) {
            return 0.0;
        }

        $lat1 = deg2rad($this->latitude);
        $lon1 = deg2rad($this->longitude);
        $lat2 = deg2rad($latitude);
        $lon2 = deg2rad($longitude);

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;

        $a = sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlon / 2) * sin($dlon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return 6371 * $c; // Earth's radius in km
    }

    
    public function parseLocation(): ?array
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        return [
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude
        ];
    }

    protected static function boot()
    {
        parent::boot();


    }
}
