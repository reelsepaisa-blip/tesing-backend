<?php

namespace App\Enums;

enum PromoType: string
{
    case FIXED = 'fixed';             // Fixed amount discount
    case PERCENTAGE = 'percentage';    // Percentage discount
    case CASHBACK = 'cashback';       // Cashback to wallet
    case REFERRAL = 'referral';       // Referral reward

    
    public function label(): string
    {
        return match($this) {
            self::FIXED => 'Fixed Amount',
            self::PERCENTAGE => 'Percentage',
            self::CASHBACK => 'Cashback',
            self::REFERRAL => 'Referral',
        };
    }

    
    public function requiresMinAmount(): bool
    {
        return match($this) {
            self::FIXED, self::PERCENTAGE => true,
            self::CASHBACK, self::REFERRAL => false,
        };
    }

    
    public function hasMaxDiscount(): bool
    {
        return match($this) {
            self::PERCENTAGE => true,
            self::FIXED, self::CASHBACK, self::REFERRAL => false,
        };
    }

    
    public function affectsFare(): bool
    {
        return match($this) {
            self::FIXED, self::PERCENTAGE => true,
            self::CASHBACK, self::REFERRAL => false,
        };
    }
}
