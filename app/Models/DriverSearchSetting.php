<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DriverSearchSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'round1_radius_km',
        'round2_radius_km',
        'round3_radius_km',
        'is_active',
        'driver_referrer_reward',
        'driver_referred_reward',
        'user_referrer_reward',
        'user_referred_reward',
    ];

    protected $casts = [
        'round1_radius_km' => 'float',
        'round2_radius_km' => 'float',
        'round3_radius_km' => 'float',
        'is_active' => 'boolean',
        'driver_referrer_reward' => 'decimal:2',
        'driver_referred_reward' => 'decimal:2',
        'user_referrer_reward' => 'decimal:2',
        'user_referred_reward' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (DriverSearchSetting $model): void {
            if ($model->round1_radius_km >= $model->round2_radius_km) {
                throw new \Illuminate\Validation\ValidationException(
                    validator([], []),
                    ['round1_radius_km' => 'Round 1 must be less than Round 2.']
                );
            }
            
            if ($model->round2_radius_km >= $model->round3_radius_km) {
                throw new \Illuminate\Validation\ValidationException(
                    validator([], []),
                    ['round2_radius_km' => 'Round 2 must be less than Round 3.']
                );
            }
        });

        static::saved(function (): void {
            Cache::forget(self::cacheKey());
        });

        static::deleted(function (): void {
            Cache::forget(self::cacheKey());
        });
    }

    public static function defaults(): array
    {
        return [
            'round1_radius_km' => 5,
            'round2_radius_km' => 10,
            'round3_radius_km' => 15,
            'is_active' => true,
            'driver_referrer_reward' => 100.00,
            'driver_referred_reward' => 100.00,
            'user_referrer_reward' => 100.00,
            'user_referred_reward' => 100.00,
        ];
    }
    
    public function getReferrerReward(int $roleId): float
    {
        return match($roleId) {
            2 => (float) ($this->driver_referrer_reward ?? 100.00),
            3 => (float) ($this->user_referrer_reward ?? 100.00),
            default => 100.00,
        };
    }
    
    public function getReferredReward(int $roleId): float
    {
        return match($roleId) {
            2 => (float) ($this->driver_referred_reward ?? 100.00),
            3 => (float) ($this->user_referred_reward ?? 100.00),
            default => 100.00,
        };
    }

    public static function cacheKey(): string
    {
        return 'driver_search_settings_active';
    }

    public static function getActive(): self
    {
        return Cache::remember(self::cacheKey(), now()->addMinutes(10), function () {
            return self::where('is_active', true)->latest('id')->first()
                ?? self::make(self::defaults());
        });
    }

    public function getRadiusForRound(int $round): float
    {
        $radii = [
            $this->round1_radius_km ?? self::defaults()['round1_radius_km'],
            $this->round2_radius_km ?? self::defaults()['round2_radius_km'],
            $this->round3_radius_km ?? self::defaults()['round3_radius_km'],
        ];

        $roundIndex = max(1, $round) - 1;

        if (array_key_exists($roundIndex, $radii)) {
            return max(0.1, (float) $radii[$roundIndex]);
        }

        return max(0.1, (float) end($radii));
    }
}

