<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class SystemConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'key',
        'value',
        'description',
        'is_encrypted',
        'is_active',
        'meta_data',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
        'is_active' => 'boolean',
        'meta_data' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function getValueAttribute($value)
    {
        if ($this->is_encrypted && !empty($value)) {
            try {
                $decrypted = decrypt($value);
                return $decrypted ?? '';
            } catch (\Exception $e) {
                return '';
            }
        }
        return $value;
    }

    public function setValueAttribute($value)
    {
        if ($this->is_encrypted && !empty($value)) {
            $this->attributes['value'] = encrypt($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public static function getValue($key, $default = null)
    {
        try {
            $config = self::where('key', $key)->where('is_active', true)->first();
            return $config ? $config->value : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    public static function setValue($key, $value, $category = 'general', $description = null)
    {
        return self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'category' => $category,
                'description' => $description,
                'is_active' => true,
            ]
        );
    }
}
