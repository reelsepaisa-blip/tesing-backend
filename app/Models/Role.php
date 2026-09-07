<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Role extends SpatieRole
{
    use HasFactory;

    protected $fillable = [
        'name',
        'guard_name'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function getPermissionModules(): array
    {
        return [
            'booking' => ['create', 'edit', 'view', 'delete'],
            'users' => ['create', 'edit', 'view', 'delete'],
            'drivers' => ['create', 'edit', 'view', 'delete'],
            'vehicles' => ['create', 'edit', 'view', 'delete'],
            'payments' => ['create', 'edit', 'view', 'delete'],
        ];
    }
}
