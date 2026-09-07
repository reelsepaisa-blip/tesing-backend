<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\PreventsDemoDeletion;

    protected $fillable = [
        'documentable_id',
        'documentable_type',
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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];


    protected static function boot()
    {
        parent::boot();

        // Prevent creating documents for user ID 2
        static::creating(function ($document) {
            $userId = auth()->id();
            if ($userId === 2) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'restricted' => ['You do not have permission to create documents.'],
                ]);
            }
        });

        // Prevent updating documents for user ID 2
        static::updating(function ($document) {
            $userId = auth()->id();
            if ($userId === 2) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'restricted' => ['You do not have permission to edit documents.'],
                ]);
            }
        });

        // Prevent deleting documents for user ID 2
        static::deleting(function ($document) {
            $userId = auth()->id();
            if ($userId === 2) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'restricted' => ['In demo mode you are not deleting data...'],
                ]);
            }
        });

        // Prevent force deleting documents for user ID 2
        static::forceDeleting(function ($document) {
            $userId = auth()->id();
            if ($userId === 2) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'restricted' => ['In demo mode you are not deleting data...'],
                ]);
            }
        });

        static::saved(function ($document) {
            if ($document->wasChanged('status') && in_array($document->status, ['approved', 'rejected'])) {
                $driver = null;

                if ($document->documentable_type === User::class) {
                    $user = $document->documentable;
                    if ($user && $user->role_id == 2) { // Driver role
                        $driver = $user;
                        $user->updateVerificationStatus();
                    }
                } else if ($document->documentable_type === \App\Models\Vehicle::class) {
                    $vehicle = $document->documentable;
                    if ($vehicle) {
                        $vehicle = $vehicle->load('driver');
                        if ($vehicle->driver) {
                            $driver = $vehicle->driver;
                            $vehicle->driver->updateVerificationStatus();
                        }
                    }
                }

                if ($driver) {
                    $notificationService = app(\App\Services\NotificationService::class);
                    $notificationService->sendDocumentNotification($driver, $document, $document->status);
                }
            } else {
                if ($document->documentable_type === User::class) {
                    $user = $document->documentable;
                    if ($user && $user->role_id == 2) { // Driver role
                        $user->updateVerificationStatus();
                    }
                } else if ($document->documentable_type === \App\Models\Vehicle::class) {
                    $vehicle = $document->documentable;
                    if ($vehicle) {
                        $vehicle = $vehicle->load('driver');
                        if ($vehicle->driver) {
                            $vehicle->driver->updateVerificationStatus();
                        }
                    }
                }
            }
        });

        static::deleted(function ($document) {
            if ($document->documentable_type === User::class) {
                $user = $document->documentable;
                if ($user && $user->role_id == 2) { // Driver role
                    $user->updateVerificationStatus();
                }
            } else if ($document->documentable_type === \App\Models\Vehicle::class) {
                $vehicle = $document->documentable;
                if ($vehicle) {
                    $vehicle = $vehicle->load('driver');
                    if ($vehicle->driver) {
                        $vehicle->driver->updateVerificationStatus();
                    }
                }
            }
        });
    }


    public function documentable()
    {
        return $this->morphTo();
    }


    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }


    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }


    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }


    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }


    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }


    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<', now()->toDateString());
    }


    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->whereBetween('expiry_date', [
            now()->toDateString(),
            now()->addDays($days)->toDateString()
        ]);
    }


    public function isApproved()
    {
        return $this->status === 'approved';
    }


    public function isPending()
    {
        return $this->status === 'pending';
    }


    public function isRejected()
    {
        return $this->status === 'rejected';
    }


    public function isExpired()
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }


    public function isExpiringSoon($days = 30)
    {
        if (!$this->expiry_date) {
            return false;
        }

        return $this->expiry_date->isBetween(now(), now()->addDays($days));
    }


    public function getStatusWithColor()
    {
        return [
            'status' => $this->status,
            'color' => match ($this->status) {
                'approved' => 'green',
                'pending' => 'yellow',
                'rejected' => 'red',
                default => 'gray'
            }
        ];
    }


    public function getFrontFileUrlAttribute()
    {
        return $this->file_front ? asset('storage/' . $this->file_front) : null;
    }

    public function getBackFileUrlAttribute()
    {
        return $this->file_back ? asset('storage/' . $this->file_back) : null;
    }


    public static function getDriverRequiredTypes(): array
    {
        $defaultTypes = [
            'government_id_front',
            'government_id_back',
            'driving_license_front',
            'driving_license_back',
            'vehicle_registration',
            'vehicle_insurance',
            'vehicle_permit',
            'profile_photo',
        ];

        $documentLists = DocumentList::query()
            ->active()
            ->get();

        $dynamicTypes = [];

        foreach ($documentLists as $document) {
            $normalized = self::normalizeDocumentType($document->name);

            if (!$normalized) {
                continue;
            }

            if ($document->type === 'driver') {
                $dynamicTypes[] = $normalized;
                $dynamicTypes[] = "{$normalized}_front";
                $dynamicTypes[] = "{$normalized}_back";
            } else {
                $dynamicTypes[] = $normalized;
            }
        }

        return array_values(array_unique(array_filter(array_merge($defaultTypes, $dynamicTypes))));
    }


    public static function normalizeDocumentType(?string $type): ?string
    {
        if (empty($type)) {
            return null;
        }

        $type = trim($type);
        $type = Str::lower($type);

        $suffix = '';
        foreach (['_front', '_back'] as $possibleSuffix) {
            if (Str::endsWith($type, $possibleSuffix)) {
                $suffix = $possibleSuffix;
                $type = Str::beforeLast($type, $possibleSuffix);
                break;
            }
        }

        $type = str_replace(' ', '_', $type);
        $type = str_replace(['_certificate', '_cert'], '', $type);
        $type = preg_replace('/_{2,}/', '_', $type);
        $type = trim($type, '_');

        if ($type === '') {
            return null;
        }

        return $suffix ? "{$type}{$suffix}" : $type;
    }
}
