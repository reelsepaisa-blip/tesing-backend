<?php

namespace App\Enums;

enum ComplaintStatus: string
{
    case PENDING = 'pending';           // Initial state when complaint is created
    case ASSIGNED = 'assigned';         // Assigned to support agent
    case IN_PROGRESS = 'in_progress';   // Being handled by support
    case RESOLVED = 'resolved';         // Issue resolved
    case CLOSED = 'closed';             // Complaint closed
    case ESCALATED = 'escalated';       // Escalated to higher level

    
    public function availableTransitions(): array
    {
        return match($this) {
            self::PENDING => [self::ASSIGNED, self::CLOSED],
            self::ASSIGNED => [self::IN_PROGRESS, self::ESCALATED, self::CLOSED],
            self::IN_PROGRESS => [self::RESOLVED, self::ESCALATED, self::CLOSED],
            self::RESOLVED => [self::CLOSED, self::IN_PROGRESS],
            self::CLOSED => [],
            self::ESCALATED => [self::IN_PROGRESS, self::RESOLVED, self::CLOSED],
        };
    }

    
    public function canTransitionTo(ComplaintStatus $targetState): bool
    {
        return in_array($targetState, $this->availableTransitions());
    }

    
    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::ASSIGNED => 'Assigned',
            self::IN_PROGRESS => 'In Progress',
            self::RESOLVED => 'Resolved',
            self::CLOSED => 'Closed',
            self::ESCALATED => 'Escalated',
        };
    }

    
    public function isFinal(): bool
    {
        return $this === self::CLOSED;
    }

    
    public function requiresAgent(): bool
    {
        return in_array($this, [self::ASSIGNED, self::IN_PROGRESS, self::ESCALATED]);
    }
}
