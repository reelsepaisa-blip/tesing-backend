<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\PreventsDemoDeletion;

    protected $fillable = [
        'ticket_number',
        'user_id',
        'booking_id',
        'transection_id',
        'category',
        'subject',
        'priority',
        'status',
        'assigned_to',
        'last_reply_at',
        'resolved_at',
        'closed_at',
        'resolution_note',
        'meta_data',
    ];

    protected $casts = [
        'last_reply_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta_data' => 'array',
    ];

    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_WAITING = 'waiting';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';
    const CATEGORY_BOOKING = 'booking';
    const CATEGORY_AFTER_RIDE = 'after_ride';
    const CATEGORY_RIDE_ISSUE = 'Ride Issue';
    const CATEGORY_PAYMENT_WALLET = 'Payment & Wallet';
    const CATEGORY_PAYMENT = 'payment';
    const CATEGORY_DRIVER = 'driver';
    const CATEGORY_OFFER = 'Offer & Reward';
    const CATEGORY_ACCOUNT = 'account';
    const CATEGORY_TECHNICAL = 'App Related';
    const CATEGORY_OTHER = 'Other';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function attachments()
    {
        return $this->hasMany(SupportAttachment::class, 'ticket_id');
    }

    public function activities()
    {
        return $this->hasMany(SupportActivity::class, 'ticket_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }

    public function scopeClosed($query)
    {
        return $query->whereNotNull('closed_at');
    }

    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_to');
    }

    public function scopeNeedsAttention($query)
    {
        return $query->where(function ($query) {
            $query
                ->whereNull('last_reply_at')
                ->orWhere('last_reply_at', '<=', now()->subHours(24));
        })->whereNull('resolved_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function isAssigned(): bool
    {
        return $this->assigned_to !== null;
    }

    public function needsAttention(): bool
    {
        if ($this->resolved_at || $this->closed_at) {
            return false;
        }

        return !$this->last_reply_at || $this->last_reply_at->diffInHours(now()) >= 24;
    }

    public function getResponseTime(): ?int
    {
        $firstMessage = $this->messages()->oldest()->first();
        if (!$firstMessage) {
            return null;
        }

        $firstResponse = $this
            ->messages()
            ->where('user_id', '!=', $this->user_id)
            ->oldest()
            ->first();

        if (!$firstResponse) {
            return null;
        }

        return $firstMessage->created_at->diffInMinutes($firstResponse->created_at);
    }

    public function getResolutionTime(): ?int
    {
        if (!$this->resolved_at) {
            return null;
        }

        return $this->created_at->diffInMinutes($this->resolved_at);
    }

    public function assign(User $user): void
    {
        $oldAssignee = $this->assigned_to;
        $this->update(['assigned_to' => $user->id]);

        $this->activities()->create([
            'user_id' => auth()->id() ?? 1,
            'type' => 'assigned',
            'description' => "Ticket assigned to {$user->name}",
            'meta_data' => [
                'old_assignee' => $oldAssignee,
                'new_assignee' => $user->id,
            ],
        ]);
    }

    public function resolve(?string $note = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);

        $this->activities()->create([
            'user_id' => auth()->id() ?? 1,
            'type' => 'resolved',
            'description' => 'Ticket marked as resolved',
            'meta_data' => [
                'resolution_note' => $note,
            ],
        ]);
    }

    public function close(?string $note = null): void
    {
        $this->update([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
            'resolution_note' => $note ?: $this->resolution_note,
        ]);

        $this->activities()->create([
            'user_id' => auth()->id() ?? 1,
            'type' => 'closed',
            'description' => 'Ticket closed',
            'meta_data' => [
                'resolution_note' => $note,
            ],
        ]);
    }

    public function reopen(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_OPEN,
            'resolved_at' => null,
            'closed_at' => null,
        ]);

        $this->activities()->create([
            'user_id' => auth()->id() ?? 1,
            'type' => 'reopened',
            'description' => 'Ticket reopened',
            'meta_data' => [
                'reason' => $reason,
            ],
        ]);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ticket) {
            if (!$ticket->ticket_number) {
                $ticket->ticket_number = static::generateTicketNumber();
            }

            if (!$ticket->status) {
                $ticket->status = self::STATUS_OPEN;
            }

            if (!$ticket->priority) {
                $ticket->priority = self::PRIORITY_MEDIUM;
            }
        });
    }

    protected static function generateTicketNumber(): string
    {
        $prefix = 'TKT';
        $date = now()->format('ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return "{$prefix}{$date}{$random}";
    }
}
