<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'role_id',
        'module',
        'can_create',
        'can_edit',
        'can_view',
        'can_delete',
        'can_all'
    ];

    protected $casts = [
        'can_create' => 'boolean',
        'can_edit' => 'boolean',
        'can_view' => 'boolean',
        'can_delete' => 'boolean',
        'can_all' => 'boolean',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}








