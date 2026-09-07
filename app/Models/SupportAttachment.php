<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class SupportAttachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'message_id',
        'user_id',
        'name',
        'file_name',
        'file_path',
        'file_size',
        'file_type',
        'is_internal',
        'meta_data',
        'filename',
        'original_name',
        'mime_type',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'is_internal' => 'boolean',
        'meta_data' => 'array',
    ];

    public function getFilenameAttribute()
    {
        return $this->attributes['file_name'] ?? null;
    }

    public function getOriginalNameAttribute()
    {
        return $this->attributes['name'] ?? null;
    }

    public function getMimeTypeAttribute()
    {
        return $this->attributes['file_type'] ?? null;
    }

    public function setFilenameAttribute($value)
    {
        $this->attributes['file_name'] = $value;
    }

    public function setOriginalNameAttribute($value)
    {
        $this->attributes['name'] = $value;
    }

    public function setMimeTypeAttribute($value)
    {
        $this->attributes['file_type'] = $value;
    }

    public function fill(array $attributes)
    {
        if (isset($attributes['filename']) && !isset($attributes['file_name'])) {
            $attributes['file_name'] = $attributes['filename'];
        }
        if (isset($attributes['original_name']) && !isset($attributes['name'])) {
            $attributes['name'] = $attributes['original_name'];
        }
        if (isset($attributes['mime_type']) && !isset($attributes['file_type'])) {
            $attributes['file_type'] = $attributes['mime_type'];
        }
        
        return parent::fill($attributes);
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function message()
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePublic($query)
    {
        return $query->where('is_internal', false);
    }

    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('file_type', 'like', $type . '%');
    }

    public function getUrl(): string
    {
        return Storage::url($this->file_path);
    }

    public function getFormattedSize(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function isImage(): bool
    {
        return str_starts_with($this->file_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->file_type === 'application/pdf';
    }

    public function isFromUser(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($attachment) {
            if ($attachment->isForceDeleting()) {
                Storage::delete($attachment->file_path);
            }
        });

        static::created(function ($attachment) {
            $attachment->ticket->activities()->create([
                'user_id' => $attachment->user_id,
                'type' => 'attachment',
                'description' => $attachment->is_internal ? 'Internal file attached' : 'File attached',
                'meta_data' => [
                    'attachment_id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_type' => $attachment->file_type,
                    'file_size' => $attachment->file_size,
                    'is_internal' => $attachment->is_internal,
                ],
            ]);
        });
    }
}








