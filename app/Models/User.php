<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\UserDebt;
use App\Models\DriverAttendance;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes, \App\Traits\PreventsDemoDeletion;


    protected $fillable = [
        'name',
        'email',
        'firebase_uid',
        'google_id',
        'apple_id',
        'phone',
        'address',
        'country_code',
        'gender',
        'password',
        'password_reset_token',
        'password_reset_expires_at',
        'role_id',
        'role', // Virtual attribute for role name
        'profile_photo',
        'device_token',
        'login_device',
        'bearer_token',
        'token_expires_at',
        'is_online',
        'is_verified',
        'verified_at',
        'last_location_at',
        'last_latitude',
        'last_longitude',
        'email_verified_at',
        'phone_verified_at',
        'status', // active, inactive, blocked, under_review
        'is_register',
        'step_0',
        'step_1',
        'step_2',
        'step_3',
        'referral_code',
        'referred_by',
        'meta_data',
        'last_location_update',
        'date_of_birth',
        'current_booking_id',
        'created_at',
        'updated_at',
        'select_latitude',
        'select_longitude',
        'city_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'device_token',
        'bearer_token',
        'token_expires_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_location_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'password_reset_expires_at' => 'datetime',
        'last_latitude' => 'decimal:8',
        'last_longitude' => 'decimal:8',
        'is_online' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'meta_data' => 'array',
        'role_id' => 'integer',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        // Block drivers and riders from admin panel
        if (in_array((int) $this->role_id, [2, 3], true)) {
            return false;
        }
        return $this->status === 'active';
    }

    public function getCountryCodeAttribute($value)
    {
        return $value ?? '';
    }

    public function getFcmTokenAttribute()
    {
        return $this->device_token;
    }

    public function setFcmTokenAttribute($value)
    {
        $this->attributes['device_token'] = $value;
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function debts()
    {
        return $this->hasMany(UserDebt::class);
    }

    public function pendingDebts()
    {
        return $this->debts()->open();
    }

    public function driverProfile()
    {
        return $this->hasOne(DriverProfile::class, 'driver_id');
    }


    public function bank_accounts()
    {
        return $this->hasMany(BankAccount::class, 'driver_id');
    }


    public function upi_accounts()
    {
        return $this->hasMany(UpiAccount::class, 'driver_id');
    }


    public function refunds()
    {
        return $this->hasMany(Refund::class, 'driver_id');
    }


    public function driverIncentives()
    {
        return $this->hasMany(DriverIncentive::class, 'driver_id');
    }


    public function driverIncentiveProgress()
    {
        return $this->hasMany(DriverIncentiveProgress::class, 'driver_id');
    }


    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }


    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }



    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'driver_id');
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function bookingsAsUser()
    {
        return $this->hasMany(Booking::class, 'user_id');
    }

    public function bookingsAsDriver()
    {
        return $this->hasMany(Booking::class, 'driver_id');
    }

    public function bookings()
    {
        return $this->bookingsAsUser();
    }

    public function promoUsages()
    {
        return $this->hasMany(PromoUsage::class);
    }

    public function transactions()
    {
        return $this->hasManyThrough(WalletTransaction::class, Wallet::class, 'user_id', 'wallet_id');
    }

    public function walletTransactions()
    {
        return $this->transactions();
    }

    public function currentLocation()
    {
        return $this->hasOne(DriverLocation::class, 'driver_id')->latest('recorded_at');
    }

    public function driverAttendance()
    {
        return $this->hasMany(DriverAttendance::class, 'driver_id');
    }

    public function currentAttendanceSession()
    {
        return $this->hasOne(DriverAttendance::class, 'driver_id')
            ->whereNull('offline_time');
    }

    public function emergencyContacts()
    {
        return $this->hasMany(EmergencyContact::class);
    }

    public function primaryEmergencyContact()
    {
        return $this->hasOne(EmergencyContact::class)->where('is_primary', true);
    }

    public function supportTickets()
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function driverSavedLocations()
    {
        return $this->hasMany(DriverSavedLocation::class, 'driver_id');
    }

    public function driverLocations()
    {
        return $this->hasMany(DriverLocation::class, 'driver_id');
    }


    public function rideTypes()
    {
        return $this->belongsToMany(RideType::class, 'driver_ride_types', 'driver_id', 'ride_type_id')
            ->withPivot('is_active', 'meta_data')
            ->withTimestamps();
    }


    public function activeRideTypes()
    {
        return $this->rideTypes()->wherePivot('is_active', true);
    }

    public function scopeDrivers($query)
    {
        return $query->where('role_id', 2);
    }

    public function scopeUsers($query)
    {
        return $query->where('role_id', 3);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    public function isDriver(): bool
    {
        return (int) $this->role_id === 2;
    }

    public function isUser(): bool
    {
        return (int) $this->role_id === 3;
    }

    public function getRoleAttribute(): string
    {
        // Prefer Spatie role name when relation is loaded (custom admin roles share role_id=1).
        if ($this->relationLoaded('roles') && $this->roles->isNotEmpty()) {
            return (string) $this->roles->first()->name;
        }

        return match ($this->role_id) {
            1 => 'admin',
            2 => 'driver',
            3 => 'user',
            4 => 'support',
            default => 'user'
        };
    }

    public function setRoleAttribute($value): void
    {
        $this->role_id = match (strtolower($value)) {
            'admin', 'superadmin', 'super_admin' => 1,
            'driver' => 2,
            'user' => 3,
            'support' => 4,
            default => 3
        };
    }

    public function getAvatarAttribute(): ?string
    {
        return $this->profile_photo;
    }

    public function setAvatarAttribute($value): void
    {
        $this->profile_photo = $value;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function setIsActiveAttribute($value): void
    {
        $this->status = $value ? 'active' : 'inactive';
    }

    public function getIsVerifiedAttribute(): bool
    {
        if (!$this->isDriver()) {
            return true; // Non-drivers are considered verified
        }

        if (array_key_exists('is_verified', $this->attributes)) {
            return (bool) $this->attributes['is_verified'];
        }

        if (!$this->relationLoaded('driverProfile')) {
            return false;
        }

        return $this->driverProfile && $this->driverProfile->isVerified();
    }

    public function getIsAvailableAttribute(): bool
    {
        if (!$this->isDriver()) {
            return false;
        }

        return $this->isActive() && $this->is_online && $this->canGoOnline();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    public function isUnderReview(): bool
    {
        return $this->status === 'under_review';
    }

    public function canGoOnline(): bool
    {
        if (!$this->isDriver()) {
            return false;
        }

        return $this->isActive()
            && $this->is_verified
            && $this->driverProfile
            && $this->vehicles()->where('status', 'active')->exists();
    }


    public function updateVerificationStatus(): bool
    {
        if (!$this->isDriver()) {
            $this->update(['is_verified' => true, 'verified_at' => now()]);
            return true;
        }

        $requiredTypeMap = DocumentList::getRequiredTypeMap();
        $requiredDriverTypes = $requiredTypeMap['driver'] ?? [];
        $requiredVehicleTypes = $requiredTypeMap['vehicle'] ?? [];

        $userDocumentsQuery = $this->documents();
        if (!empty($requiredDriverTypes)) {
            $userDocumentsQuery->whereIn('type', $requiredDriverTypes);
        }

        $userDocuments = $userDocumentsQuery->get(['id', 'status']);
        $totalUserDocuments = $userDocuments->count();
        $approvedUserDocuments = $userDocuments->where('status', 'approved')->count();

        $totalVehicleDocuments = 0;
        $approvedVehicleDocuments = 0;
        foreach ($this->vehicles as $vehicle) {
            $vehicleDocumentsQuery = $vehicle->documents();

            if (!empty($requiredVehicleTypes)) {
                $vehicleDocumentsQuery->whereIn('type', $requiredVehicleTypes);
            }

            $vehicleDocuments = $vehicleDocumentsQuery->get(['id', 'status']);
            $totalVehicleDocuments += $vehicleDocuments->count();
            $approvedVehicleDocuments += $vehicleDocuments->where('status', 'approved')->count();
        }

        $totalDocuments = $totalUserDocuments + $totalVehicleDocuments;
        $approvedDocuments = $approvedUserDocuments + $approvedVehicleDocuments;

        $isVerified = $totalDocuments > 0
            && $totalDocuments === $approvedDocuments
            && $this->hasApprovedVehicleRegistration();

        $updateData = [
            'is_verified' => $isVerified,
            'verified_at' => $isVerified ? now() : null
        ];

        if ($isVerified && in_array($this->status, ['under_review', 'inactive'])) {
            $updateData['status'] = 'active';
        }

        $this->update($updateData);

        return $isVerified;
    }


    public function hasAllDocumentsApproved(): bool
    {
        if (!$this->isDriver()) {
            return true;
        }

        $totalDocuments = $this->documents()->count();
        $approvedDocuments = $this->documents()->where('status', 'approved')->count();

        return $totalDocuments > 0 && $totalDocuments === $approvedDocuments;
    }


    public function hasApprovedVehicleRegistration(): bool
    {
        if (!$this->isDriver()) {
            return true;
        }

        $meta = $this->driverProfile?->meta_data;

        if (is_array($meta)) {
            if (array_key_exists('vehicle_registration_status', $meta)) {
                return $meta['vehicle_registration_status'] === 'approved';
            }

            if (array_key_exists('vehicle_registration_approved', $meta)) {
                return (bool) $meta['vehicle_registration_approved'];
            }
        }

        return false;
    }


    public function getDocumentVerificationSummary(): array
    {
        $documents = $this->documents()->get();

        return [
            'total' => $documents->count(),
            'approved' => $documents->where('status', 'approved')->count(),
            'pending' => $documents->where('status', 'pending')->count(),
            'rejected' => $documents->where('status', 'rejected')->count(),
            'is_complete' => $this->hasAllDocumentsApproved(),
            'missing_documents' => $this->getMissingDocuments()
        ];
    }


    public function getMissingDocuments(): array
    {
        if (!$this->isDriver()) {
            return [];
        }

        $requiredTypes = Document::getDriverRequiredTypes();
        $existingTypes = $this->documents()->pluck('type')->toArray();

        return array_diff($requiredTypes, $existingTypes);
    }

    public function generateReferralCode(): string
    {


        $cleanName = strtoupper(preg_replace('/[^A-Za-z]/', '', $this->name));



        $namePrefix = substr($cleanName, 0, 3);

        if (strlen($namePrefix) < 3) {
            $namePrefix = str_pad($namePrefix, 3, 'X', STR_PAD_RIGHT);
        }



        do {
            $code = $namePrefix . rand(1000, 9999);
        } while (self::where('referral_code', $code)->exists());



        $this->update(['referral_code' => $code]);



        return $code;
    }


    public function shouldUpdateReferralCode(): bool
    {
        return str_starts_with($this->referral_code, 'XXX');
    }


    public function updateReferralCodeIfNeeded(): string
    {
        if ($this->shouldUpdateReferralCode()) {
            return $this->generateReferralCode();
        }

        return $this->referral_code;
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($user) {
            $user->wallet()->create([
                'balance' => 0,
            ]);
        });

        static::updated(function ($user) {
            if ($user->isDirty('is_online') || $user->isDirty('status')) {
                if ($user->isDirty('status')) {
                    $originalStatus = $user->getOriginal('status');
                    $newStatus = $user->status;

                    if ($originalStatus !== 'blocked' && $newStatus === 'blocked') {
                        static::where('id', $user->id)->update([
                            'token_expires_at' => now()->subMinute(), // Set to 1 minute ago to ensure it's expired
                            'is_online' => 0 // Set is_online to 0 when blocked
                        ]);

                        $user->token_expires_at = now()->subMinute();
                        $user->is_online = 0;

                        if ($user->isDriver()) {
                            try {
                                $activeSession = DriverAttendance::getCurrentOnlineSession($user->id);
                                if ($activeSession) {
                                    $activeSession->markOffline();
                                }
                            } catch (\Exception $e) {
                                // Error handling
                            }
                        }
                    }
                }

                $bearerToken = $user->bearer_token;
                if (!$bearerToken) {
                    $bearerToken = static::where('id', $user->id)->value('bearer_token');
                }

                if ($bearerToken) {
                    $token = str_replace('Bearer_', '', $bearerToken);
                    Cache::forget('auth:bearer_user:' . $token);
                    Cache::forget('auth:bearer_user:' . 'Bearer_' . $token);
                }
            }
        });
    }
}
