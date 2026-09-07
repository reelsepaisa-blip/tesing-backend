<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'icon',
        'sound',
        'data',
        'is_read',
        'read_at',
        'is_sent',
        'sent_at',
        'fcm_message_id',
        'status',
        'error_message'
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'is_sent' => 'boolean',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    
    public function markAsRead(): bool
    {
        return $this->update([
            'is_read' => true,
            'read_at' => now()->utc()
        ]);
    }

    
    public function markAsSent(string $fcmMessageId = null): bool
    {
        return $this->update([
            'is_sent' => true,
            'sent_at' => now()->utc(),
            'status' => 'sent',
            'fcm_message_id' => $fcmMessageId
        ]);
    }

    
    public function markAsFailed(string $errorMessage = null): bool
    {
        return $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage
        ]);
    }

    
    public function markAsDelivered(): bool
    {
        return $this->update([
            'status' => 'delivered'
        ]);
    }

    
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    
    public function scopeSent($query)
    {
        return $query->where('is_sent', true);
    }

    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->utc()->subDays($days));
    }

    
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'sent' => 'success',
            'delivered' => 'success',
            'failed' => 'danger',
            'pending' => 'warning',
            default => 'secondary'
        };
    }

    
    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            'document_status' => 'heroicon-o-document-text',
            'booking_update' => 'heroicon-o-truck',
            'driver_verified' => 'heroicon-o-check-circle',
            'payment_received' => 'heroicon-o-currency-dollar',
            'trip_completed' => 'heroicon-o-flag',
            default => 'heroicon-o-bell'
        };
    }
}
