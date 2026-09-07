<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'driver_id',
        'account_holder_name',
        'account_number',
        'ifsc_code',
        'bank_name',
        'branch_name',
        'account_type',
        'is_verified',
        'is_primary',
        'verified_at',
        'meta_data',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
        'meta_data' => 'array',
    ];

    
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    
    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
