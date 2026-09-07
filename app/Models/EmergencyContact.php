<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmergencyContact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'mobile_number',
        'is_primary',
        'meta_data',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'meta_data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function getFormattedMobileAttribute()
    {
        $mobile = $this->mobile_number;
        if (strlen($mobile) >= 10) {
            return substr($mobile, 0, 5) . ' ' . substr($mobile, 5);
        }
        return $mobile;
    }

    public static function validationRules()
    {
        return [
            'name' => 'required|string|max:255',
            'mobile_number' => 'required|string|max:15|regex:/^[0-9+\-\s()]+$/',
        ];
    }

    public function isPrimary(): bool
    {
        return $this->is_primary;
    }

    public function setAsPrimary(): void
    {
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->update(['is_primary' => true]);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($contact) {
            if ($contact->is_primary) {
                static::where('user_id', $contact->user_id)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);
            }
        });
    }
}
