<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'driver_id',
        'type', // license, identity, vehicle_rc, insurance, permit, etc.
        'number',
        'file_front',
        'file_back',
        'expiry_date',
        'status', // pending, approved, rejected
        'rejection_reason',
        'verified_at',
        'verified_by',
        'meta_data',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'verified_at' => 'datetime',
        'meta_data' => 'array',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' && $this->verified_at;
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(): bool
    {
        return $this->expiry_date && $this->expiry_date->diffInDays(now()) <= 30;
    }

    public static function getRequiredTypes(): array
    {
        return [
            'driving_license',
            'identity_proof',
        ];
    }

    public static function getVehicleTypes(): array
    {
        return [
            'registration_certificate',
            'insurance',
            'permit',
            'fitness_certificate',
            'vehicle_photo',
        ];
    }
}
