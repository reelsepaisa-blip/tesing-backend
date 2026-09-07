<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\PreventsDemoDeletion;

    protected $table = 'vehicles';

    protected $fillable = [
        'driver_id',
        'ride_type_id',
        'brand',
        'model',
        'year',
        'color',
        'license_plate',
        'registration_number',
        'registration_expiry',
        'insurance_expiry',
        'status',
        'rejection_reason',
        'features',
        'documents',
        'step_2',
        'step_3',
    ];

    protected $casts = [
        'registration_expiry' => 'date',
        'insurance_expiry' => 'date',
        'features' => 'array',
        'documents' => 'array',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function rideType()
    {
        return $this->belongsTo(RideType::class);
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    
    public function getDocumentsArrayAttribute()
    {
        return $this->documents ?? [];
    }

    
    public function setDocumentsArrayAttribute($value)
    {
        $this->attributes['documents'] = json_encode($value);
    }

    /**
     * Mutator to remove suffix from license_plate (e.g., "GJ12AB5678-3" -> "GJ12AB5678")
     */
    public function setLicensePlateAttribute($value)
    {
        if ($value) {
            // Remove suffix pattern like "-123" or "-3" from the end
            $this->attributes['license_plate'] = preg_replace('/-\d+$/', '', $value);
        } else {
            $this->attributes['license_plate'] = $value;
        }
    }
}
