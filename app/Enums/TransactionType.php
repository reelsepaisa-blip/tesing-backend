<?php

namespace App\Enums;

enum TransactionType: string
{
    case CREDIT = 'credit';           // Add money to wallet
    case DEBIT = 'debit';            // Remove money from wallet
    case HOLD = 'hold';              // Hold amount for pending transaction
    case RELEASE = 'release';         // Release held amount
    case REFUND = 'refund';          // Refund amount to wallet

    
    public function label(): string
    {
        return match($this) {
            self::CREDIT => 'Credit',
            self::DEBIT => 'Debit',
            self::HOLD => 'Hold',
            self::RELEASE => 'Release',
            self::REFUND => 'Refund',
        };
    }

    
    public function affectsBalance(): bool
    {
        return match($this) {
            self::CREDIT, self::DEBIT, self::REFUND => true,
            self::HOLD, self::RELEASE => false,
        };
    }

    
    public function affectsHoldAmount(): bool
    {
        return match($this) {
            self::HOLD, self::RELEASE => true,
            self::CREDIT, self::DEBIT, self::REFUND => false,
        };
    }

    
    public function getSign(): int
    {
        return match($this) {
            self::CREDIT, self::REFUND, self::RELEASE => 1,
            self::DEBIT, self::HOLD => -1,
        };
    }
}
