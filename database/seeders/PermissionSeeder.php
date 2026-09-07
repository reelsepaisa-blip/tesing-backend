<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Generate permissions for all Filament BaseResources and assign all to the admin role.
     */
    public function run(): void
    {
        $this->generateResourcePermissions();
        $this->ensureRolePermissionsExist();
        $this->assignAllPermissionsToAdminRole();
    }

    /**
     * Same logic as permissions:generate - create view/create/edit/delete for each BaseResource.
     */
    protected function generateResourcePermissions(): void
    {
        $resourcePath = app_path('Filament/Resources');
        if (! File::isDirectory($resourcePath)) {
            return;
        }

        foreach (File::files($resourcePath) as $file) {
            $className = 'App\\Filament\\Resources\\' . $file->getBasename('.php');
            if (class_exists($className) && is_subclass_of($className, 'App\\Filament\\Resources\\BaseResource')) {
                $className::createResourcePermissions();
            }
        }
    }

    /**
     * Ensure permissions used by RoleResource (roles.view, etc.) exist.
     */
    protected function ensureRolePermissionsExist(): void
    {
        foreach (['view', 'create', 'edit', 'delete'] as $action) {
            Permission::firstOrCreate(
                ['name' => "roles.{$action}", 'guard_name' => 'web'],
                ['name' => "roles.{$action}", 'guard_name' => 'web']
            );
        }
    }

    /**
     * Give the admin role every permission (guard web) so admins can access all resources.
     */
    protected function assignAllPermissionsToAdminRole(): void
    {
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if (! $adminRole) {
            return;
        }

        $permissionNames = Permission::where('guard_name', 'web')->pluck('name')->toArray();
        $adminRole->syncPermissions($permissionNames);

        $this->command->info('Assigned ' . count($permissionNames) . ' permissions to admin role.');
    }
}
