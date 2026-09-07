<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralRewardSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'referrer_reward',
        'referred_reward',
        'is_active',
        'description',
        'meta_data',
    ];

    protected $casts = [
        'referrer_reward' => 'decimal:2',
        'referred_reward' => 'decimal:2',
        'is_active' => 'boolean',
        'meta_data' => 'array',
    ];

    
    public static function getActiveSettings(): ?self
    {
        return self::where('is_active', true)->first();
    }

    
    public static function getDefaultSettings(): self
    {
        $settings = self::getActiveSettings();

        if (!$settings) {
            $settings = self::create([
                'name' => 'Default Referral Settings',
                'referrer_reward' => 100.00,
                'referred_reward' => 100.00,
                'is_active' => true,
                'description' => 'Default referral reward settings',
            ]);
        }

        return $settings;
    }
}
