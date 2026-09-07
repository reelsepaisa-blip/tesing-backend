<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactUs extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contact_us';

    protected $fillable = [
        'intro_text',
        'email',
        'phone',
        'office_address',
        'support_message',
        'additional_contacts',
        'working_hours',
        'is_active',
        'meta_data',
    ];

    protected $casts = [
        'additional_contacts' => 'array',
        'is_active' => 'boolean',
        'meta_data' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
