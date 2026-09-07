<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'message',
        'is_internal',
        'read_at',
        'meta_data',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'read_at' => 'datetime',
        'meta_data' => 'array',
    ];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }

    public function scopePublic($query)
    {
        return $query->where('is_internal', false);
    }

    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeFromUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isFromUser(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function isInternal(): bool
    {
        return $this->is_internal;
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    public function hasAttachments(): bool
    {
        return $this->attachments()->exists();
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($message) {
            $message->ticket->update([
                'last_reply_at' => now(),
                'status' => $message->isFromUser($message->ticket->user)
                    ? SupportTicket::STATUS_WAITING
                    : SupportTicket::STATUS_IN_PROGRESS,
            ]);

            $message->ticket->activities()->create([
                'user_id' => $message->user_id,
                'type' => 'message',
                'description' => $message->is_internal ? 'Internal note added' : 'New message added',
                'meta_data' => [
                    'message_id' => $message->id,
                    'is_internal' => $message->is_internal,
                ],
            ]);
        });
    }
}








