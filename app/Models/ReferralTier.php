<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'milestone_count',
        'bonus_amount',
        'is_active',
        'description',
    ];
}
