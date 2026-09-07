<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class AboutUs extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'content',
        'intro_text',
        'sections',
        'image_url',
        'is_active',
        'sort_order',
        'meta_data',
    ];

    protected $casts = [
        'sections' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'meta_data' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    public function getImageUrlAttribute($value): string
    {
        if (empty($value)) {
            return '';
        }

        // If it's already a full URL (starts with http:// or https://), return as is
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        // If it's a storage path (starts with /storage/), convert to full URL
        if (str_starts_with($value, '/storage/')) {
            return url($value);
        }

        // If it's a relative storage path (like 'about-us/image.jpg'), convert using Storage::url
        if (!str_contains($value, '://') && !str_starts_with($value, '/')) {
            $storageUrl = Storage::url($value);
            if (str_starts_with($storageUrl, '/storage/')) {
                return url($storageUrl);
            }
            return $storageUrl;
        }

        // For any other relative paths starting with /, convert to full URL
        if (str_starts_with($value, '/')) {
            return url($value);
        }

        return $value;
    }

    public function getContentAttribute($value): ?string
    {
        if (empty($value)) {
            return $value;
        }

        // Convert relative image paths in HTML content to full URLs
        $pattern = '/(<img[^>]+src=["\'])([^"\']+)(["\'][^>]*>)/i';

        return preg_replace_callback($pattern, function ($matches) {
            $prefix = $matches[1];
            $src = $matches[2];
            $suffix = $matches[3];

            // If already a full URL, return as is
            if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
                return $prefix . $src . $suffix;
            }

            // Convert relative storage paths to full URLs
            if (str_starts_with($src, '/storage/')) {
                return $prefix . url($src) . $suffix;
            }

            // Handle storage paths without leading slash
            if (!str_contains($src, '://') && !str_starts_with($src, '/')) {
                $storageUrl = Storage::url($src);
                if (str_starts_with($storageUrl, '/storage/')) {
                    return $prefix . url($storageUrl) . $suffix;
                }
                return $prefix . $storageUrl . $suffix;
            }

            // For other relative paths starting with /
            if (str_starts_with($src, '/')) {
                return $prefix . url($src) . $suffix;
            }

            return $prefix . $src . $suffix;
        }, $value);
    }
}
