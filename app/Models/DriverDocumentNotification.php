<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDocumentNotification extends Model
{
    protected $fillable = [
        'driver_id',
        'document_list_id',
        'notified_at',
        'deadline_at',
        'is_uploaded',
        'uploaded_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'deadline_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'is_uploaded' => 'boolean',
    ];

    
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    
    public function documentList(): BelongsTo
    {
        return $this->belongsTo(DocumentList::class, 'document_list_id');
    }

    
    public function isDeadlinePassed(): bool
    {
        return now()->isAfter($this->deadline_at);
    }

    
    public function markAsUploaded(): bool
    {
        return $this->update([
            'is_uploaded' => true,
            'uploaded_at' => now(),
        ]);
    }
}
