<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'type',
        'description',
        'meta_data',
    ];

    protected $casts = [
        'meta_data' => 'array',
    ];

    const TYPE_CREATED = 'created';
    const TYPE_UPDATED = 'updated';
    const TYPE_MESSAGE = 'message';
    const TYPE_ATTACHMENT = 'attachment';
    const TYPE_ASSIGNED = 'assigned';
    const TYPE_STATUS = 'status';
    const TYPE_PRIORITY = 'priority';
    const TYPE_RESOLVED = 'resolved';
    const TYPE_REOPENED = 'reopened';
    const TYPE_CLOSED = 'closed';

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    public function isType(string $type): bool
    {
        return $this->type === $type;
    }

    public function isFromUser(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function getIcon(): string
    {
        return match ($this->type) {
            self::TYPE_CREATED => 'heroicon-o-plus-circle',
            self::TYPE_UPDATED => 'heroicon-o-pencil',
            self::TYPE_MESSAGE => 'heroicon-o-chat-bubble-left-ellipsis',
            self::TYPE_ATTACHMENT => 'heroicon-o-paper-clip',
            self::TYPE_ASSIGNED => 'heroicon-o-user',
            self::TYPE_STATUS => 'heroicon-o-arrow-path',
            self::TYPE_PRIORITY => 'heroicon-o-exclamation-triangle',
            self::TYPE_RESOLVED => 'heroicon-o-check-circle',
            self::TYPE_REOPENED => 'heroicon-o-arrow-uturn-left',
            self::TYPE_CLOSED => 'heroicon-o-x-circle',
            default => 'heroicon-o-information-circle',
        };
    }

    public function getColor(): string
    {
        return match ($this->type) {
            self::TYPE_CREATED => 'gray',
            self::TYPE_UPDATED => 'blue',
            self::TYPE_MESSAGE => 'indigo',
            self::TYPE_ATTACHMENT => 'purple',
            self::TYPE_ASSIGNED => 'pink',
            self::TYPE_STATUS => 'yellow',
            self::TYPE_PRIORITY => 'orange',
            self::TYPE_RESOLVED => 'green',
            self::TYPE_REOPENED => 'red',
            self::TYPE_CLOSED => 'gray',
            default => 'gray',
        };
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($activity) {
            if (!$activity->user_id) {
                $activity->user_id = auth()->id();
            }
        });
    }
}








