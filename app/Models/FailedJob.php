<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FailedJob extends Model
{
    protected $table = 'failed_jobs';
    
    protected $fillable = [
        'uuid',
        'connection',
        'queue',
        'payload',
        'exception',
        'failed_at',
    ];

    protected $casts = [
        'failed_at' => 'datetime',
        'payload' => 'array',
    ];

    public $timestamps = false;

    
    public function getJobClassAttribute(): string
    {
        $payload = is_string($this->payload) ? json_decode($this->payload, true) : $this->payload;
        return $payload['displayName'] ?? 'Unknown Job';
    }

    
    public function getShortExceptionAttribute(): string
    {
        $lines = explode("\n", $this->exception);
        return $lines[0] ?? 'Unknown error';
    }
}
