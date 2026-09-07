<?php

namespace App\Traits;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

trait HasResourcePermissions
{
    public static function bootHasResourcePermissions()
    {
        static::creating(function ($model) {
            static::createResourcePermissions();
        });
    }

    public static function createResourcePermissions()
    {
        $resourceName = static::getPermissionResourceName();
        $actions = ['view', 'create', 'edit', 'delete'];

        foreach ($actions as $action) {
            Permission::firstOrCreate([
                'name' => "{$resourceName}.{$action}",
                'guard_name' => 'web'
            ]);
        }
    }

    public static function getResourcePermissions(): array
    {
        $resourceName = static::getPermissionResourceName();
        return [
            'view' => "{$resourceName}.view",
            'create' => "{$resourceName}.create",
            'edit' => "{$resourceName}.edit",
            'delete' => "{$resourceName}.delete",
        ];
    }

    public static function getPermissionResourceName(): string
    {
        return Str::snake(Str::replaceLast('Resource', '', class_basename(static::class)));
    }
}
