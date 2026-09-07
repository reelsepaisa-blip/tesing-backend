<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class HelpArticleAttachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'article_id',
        'name',
        'file_name',
        'file_path',
        'file_size',
        'file_type',
        'order',
        'is_active',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function article()
    {
        return $this->belongsTo(HelpArticle::class, 'article_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
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

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($attachment) {
            if ($attachment->isForceDeleting()) {
                Storage::delete($attachment->file_path);
            }
        });
    }
}








