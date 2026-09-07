<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverMatchingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'weights',
        'is_active',
    ];

    protected $casts = [
        'weights' => 'array',
        'is_active' => 'boolean',
    ];

    public static function getDefaultIdleWeights(): array
    {
        return [
            'distance' => 35,
            'trips_today' => 20,
            'rating' => 15,
            'acceptance_rate' => 10,
            'idle_time' => 10,
            'cancel_rate' => 10,
        ];
    }

    public static function getDefaultFallbackWeights(): array
    {
        return [
            'd2p_distance' => 35,
            'trips_today' => 20,
            'rating' => 15,
            'acceptance_rate' => 10,
            'fairness_balance' => 10,
            'cancel_rate' => 10,
        ];
    }

    public static function getWeights(string $type): array
    {
        $setting = self::where('type', $type)
            ->where('is_active', true)
            ->first();

        if (!$setting) {
            return $type === 'idle'
                ? self::getDefaultIdleWeights()
                : self::getDefaultFallbackWeights();
        }

        return $setting->weights;
    }

    public static function updateWeights(string $type, array $weights): void
    {
        $total = array_sum($weights);
        if (abs($total - 100) > 0.01) {
            throw new \InvalidArgumentException('Weights must sum to 100%');
        }

        self::updateOrCreate(
            ['type' => $type],
            ['weights' => $weights, 'is_active' => true]
        );
    }
}
