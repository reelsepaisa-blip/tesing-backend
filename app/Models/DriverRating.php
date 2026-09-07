<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverRating extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'driver_id',
        'rider_id',
        'booking_id',
        'rating',
        'feedback_tags',
        'comments',
        'rated_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'feedback_tags' => 'array',
        'rated_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function scopeByDriver($query, $driverId)
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeByRider($query, $riderId)
    {
        return $query->where('rider_id', $riderId);
    }

    public function scopeByRating($query, $rating)
    {
        return $query->where('rating', $rating);
    }
}
