<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'driver_id',
        'city_id',
        'address',
        'license_number',
        'license_expiry',
        'identity_number',
        'identity_type', // aadhar, pan, voter_id, etc.
        'identity_expiry',
        'bank_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_branch',
        'account_holder_name',
        'commission_rate',
        'total_trips',
        'completed_trips',
        'cancelled_trips',
        'total_earnings',
        'total_commission',
        'rating',
        'identity_verified_at',
        'bank_verified_at',
        'address_verified_at',
        'rejection_reason',
        'meta_data',
    ];

    protected $casts = [
        'license_expiry' => 'date',
        'identity_expiry' => 'date',
        'identity_verified_at' => 'datetime',
        'bank_verified_at' => 'datetime',
        'address_verified_at' => 'datetime',
        'commission_rate' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'total_commission' => 'decimal:2',
        'rating' => 'decimal:1',
        'meta_data' => 'array',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    
    public function setRatingAttribute($value)
    {
        $this->attributes['rating'] = ($value === '' || $value === null) ? null : $value;
    }

    
    public function getRatingAttribute($value)
    {
        return $value === null ? '' : $value;
    }

    public function isVerified(): bool
    {
        return $this->identity_verified_at 
            && $this->bank_verified_at 
            && $this->address_verified_at;
    }

    public function updateStats()
    {
        $bookings = $this->driver->bookingsAsDriver();
        
        $this->update([
            'total_trips' => $bookings->count(),
            'completed_trips' => $bookings->where('status', 'completed')->count(),
            'cancelled_trips' => $bookings->where('status', 'cancelled')->count(),
            'total_earnings' => $bookings->where('status', 'completed')->sum('driver_amount'),
            'total_commission' => $bookings->where('status', 'completed')->sum('admin_commission'),
            'rating' => $bookings->where('status', 'completed')->whereNotNull('user_rating')->avg('user_rating') ?? 0,
        ]);
    }
}
