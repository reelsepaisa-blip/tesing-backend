<?php

namespace App\Enums;

enum BookingState: string
{
    case PENDING = 'pending';           // Initial state when booking is created
    case SEARCHING = 'searching';       // Looking for drivers
    case ACCEPTED = 'accepted';         // Driver accepted the booking
    case ARRIVED = 'arrived';           // Driver arrived at pickup location
    case STARTED = 'started';           // Trip started after OTP verification
    case COMPLETED = 'completed';       // Trip completed
    case CANCELLED = 'cancelled';       // Trip cancelled by either party
    case EXPIRED = 'expired';           // No drivers found or timeout

    
    public function availableTransitions(): array
    {
        return match($this) {
            self::PENDING => [self::SEARCHING, self::CANCELLED],
            self::SEARCHING => [self::ACCEPTED, self::CANCELLED, self::EXPIRED],
            self::ACCEPTED => [self::ARRIVED, self::CANCELLED],
            self::ARRIVED => [self::STARTED, self::CANCELLED],
            self::STARTED => [self::COMPLETED, self::CANCELLED],
            self::COMPLETED => [],
            self::CANCELLED => [],
            self::EXPIRED => [],
        };
    }

    
    public function canTransitionTo(BookingState $targetState): bool
    {
        return in_array($targetState, $this->availableTransitions());
    }

    
    public static function cancellableStates(): array
    {
        return [
            self::PENDING,
            self::SEARCHING,
            self::ACCEPTED,
            self::ARRIVED,
            self::STARTED,
        ];
    }

    
    public static function activeStates(): array
    {
        return [
            self::PENDING,
            self::SEARCHING,
            self::ACCEPTED,
            self::ARRIVED,
            self::STARTED,
        ];
    }

    
    public static function finalStates(): array
    {
        return [
            self::COMPLETED,
            self::CANCELLED,
            self::EXPIRED,
        ];
    }

    
    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::SEARCHING => 'Finding Driver',
            self::ACCEPTED => 'Driver Assigned',
            self::ARRIVED => 'Driver Arrived',
            self::STARTED => 'Trip Started',
            self::COMPLETED => 'Trip Completed',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
        };
    }
}
