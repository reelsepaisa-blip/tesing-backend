<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class BannerImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'city_id',
        'title',
        'description',
        'image_path',
        'image_url',
        'file_name',
        'file_type',
        'file_size',
        'width',
        'height',
        'row_position',
        'sort_order',
        'is_active',
        'link_url',
        'link_text',
        'metadata',
    ];

    protected $casts = [
        'city_id' => 'integer',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFirstRow($query)
    {
        return $query->where('row_position', 'first');
    }

    public function scopeSecondRow($query)
    {
        return $query->where('row_position', 'second');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    public function getUrl(): string
    {
        if ($this->image_path) {
            $url = Storage::url($this->image_path);

            if (str_starts_with($url, '/storage/')) {
                return url($url);
            }

            return $url;
        }

        return $this->image_url ?? '';
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

    public function getDimensions(): string
    {
        if ($this->width && $this->height) {
            return $this->width . 'x' . $this->height;
        }
        return 'Unknown';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->file_type, 'image/');
    }

    public function hasLink(): bool
    {
        return !empty($this->link_url);
    }

    public function setImagePathAttribute($value)
    {
        $this->attributes['image_path'] = $value;
        if ($value) {
            $this->attributes['image_url'] = url(Storage::url($value));
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($bannerImage) {
            if ($bannerImage->image_path) {
                $bannerImage->image_url = url(Storage::url($bannerImage->image_path));
            }

            if ($bannerImage->image_path && Storage::disk('public')->exists($bannerImage->image_path)) {
                try {
                    $filePath = Storage::disk('public')->path($bannerImage->image_path);

                    if (!$bannerImage->file_name) {
                        $bannerImage->file_name = basename($bannerImage->image_path);
                    }

                    if (!$bannerImage->file_type && file_exists($filePath)) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo) {
                            $bannerImage->file_type = finfo_file($finfo, $filePath);
                            finfo_close($finfo);
                        }
                    }

                    if (!$bannerImage->file_size && file_exists($filePath)) {
                        $bannerImage->file_size = filesize($filePath);
                    }

                    if ((!$bannerImage->width || !$bannerImage->height) && file_exists($filePath)) {
                        $imageInfo = getimagesize($filePath);
                        if ($imageInfo) {
                            $bannerImage->width = $imageInfo[0];
                            $bannerImage->height = $imageInfo[1];
                        }
                    }
                } catch (\Exception $e) {
                }
            }
        });

        static::deleting(function ($bannerImage) {
            $otherBannersUsingSameImage = static::where('id', '!=', $bannerImage->id)
                ->where('image_path', $bannerImage->image_path)
                ->exists();
            
            if (!$otherBannersUsingSameImage && $bannerImage->image_path && Storage::disk('public')->exists($bannerImage->image_path)) {
                Storage::disk('public')->delete($bannerImage->image_path);
            }
        });
    }
}
