<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrivacyPolicy extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'privacy_policies';

    protected $fillable = [
        'title',
        'intro_text',
        'sections',
        'data_sharing_text',
        'user_rights_text',
        'conclusion_text',
        'version',
        'is_active',
        'effective_date',
        'last_updated_at',
        'meta_data',
    ];

    protected $casts = [
        'sections' => 'array',
        'is_active' => 'boolean',
        'effective_date' => 'datetime',
        'last_updated_at' => 'datetime',
        'meta_data' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLatest($query)
    {
        return $query->orderBy('effective_date', 'desc')
                    ->orderBy('version', 'desc');
    }
}
