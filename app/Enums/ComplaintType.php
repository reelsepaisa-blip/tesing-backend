<?php

namespace App\Enums;

enum ComplaintType: string
{
    case DRIVER_BEHAVIOR = 'driver_behavior';       // Driver conduct issues
    case SAFETY = 'safety';                        // Safety concerns
    case OVERCHARGE = 'overcharge';                // Fare related issues
    case ROUTE = 'route';                          // Route deviation
    case CLEANLINESS = 'cleanliness';             // Vehicle cleanliness
    case CANCELLATION = 'cancellation';            // Cancellation issues
    case REFUND = 'refund';                        // Refund requests
    case TECHNICAL = 'technical';                  // App/technical issues
    case PAYMENT = 'payment';                      // Payment related issues
    case OTHER = 'other';                          // Other issues

    
    public function label(): string
    {
        return match($this) {
            self::DRIVER_BEHAVIOR => 'Driver Behavior',
            self::SAFETY => 'Safety Concern',
            self::OVERCHARGE => 'Overcharge',
            self::ROUTE => 'Route Issue',
            self::CLEANLINESS => 'Cleanliness',
            self::CANCELLATION => 'Cancellation',
            self::REFUND => 'Refund Request',
            self::TECHNICAL => 'Technical Issue',
            self::PAYMENT => 'Payment Issue',
            self::OTHER => 'Other',
        };
    }

    
    public function getPriority(): int
    {
        return match($this) {
            self::SAFETY => 1,                 // Highest priority
            self::DRIVER_BEHAVIOR => 2,
            self::REFUND, self::PAYMENT => 3,
            self::OVERCHARGE => 4,
            self::ROUTE, self::CANCELLATION => 5,
            self::TECHNICAL => 6,
            self::CLEANLINESS, self::OTHER => 7, // Lowest priority
        };
    }

    
    public function requiresImmediate(): bool
    {
        return in_array($this, [self::SAFETY, self::DRIVER_BEHAVIOR]);
    }

    
    public function requiresBooking(): bool
    {
        return in_array($this, [
            self::DRIVER_BEHAVIOR,
            self::SAFETY,
            self::OVERCHARGE,
            self::ROUTE,
            self::CLEANLINESS,
            self::CANCELLATION,
            self::REFUND,
            self::PAYMENT,
        ]);
    }

    
    public function allowsRefund(): bool
    {
        return in_array($this, [
            self::OVERCHARGE,
            self::CANCELLATION,
            self::REFUND,
            self::PAYMENT,
        ]);
    }
}
