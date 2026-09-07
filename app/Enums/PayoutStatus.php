<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case PENDING = 'pending';           // Initial state when payout is created
    case PROCESSING = 'processing';     // Being processed by payment provider
    case COMPLETED = 'completed';       // Successfully paid out
    case FAILED = 'failed';            // Failed to process
    case CANCELLED = 'cancelled';       // Cancelled by admin or system

    
    public function availableTransitions(): array
    {
        return match($this) {
            self::PENDING => [self::PROCESSING, self::CANCELLED],
            self::PROCESSING => [self::COMPLETED, self::FAILED],
            self::COMPLETED => [],
            self::FAILED => [self::PENDING],
            self::CANCELLED => [],
        };
    }

    
    public function canTransitionTo(PayoutStatus $targetState): bool
    {
        return in_array($targetState, $this->availableTransitions());
    }

    
    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
        };
    }
}
