<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected array $formDataWithPermissions = [];

    protected function isEnabledState(mixed $state): bool
    {
        if (is_bool($state)) {
            return $state;
        }

        if (is_int($state) || is_float($state)) {
            return (int) $state === 1;
        }

        if (is_string($state)) {
            return in_array(strtolower(trim($state)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    protected function resolveToggleState(array $formData, string $resourceName, string $action): mixed
    {
        $allKey = "{$resourceName}_all";
        if ($this->isEnabledState($formData[$allKey] ?? null)) {
            return true;
        }

        // 1) Nested structure: ['booking' => ['view' => true]]
        if (array_key_exists($action, $formData[$resourceName] ?? [])) {
            return $formData[$resourceName][$action];
        }

        // 2) Dotted inside nested group: ['booking' => ['booking.view' => true]]
        $nestedDotted = "{$resourceName}.{$action}";
        if (array_key_exists($nestedDotted, $formData[$resourceName] ?? [])) {
            return $formData[$resourceName][$nestedDotted];
        }

        // 3) Fully dotted at root: ['booking.view' => true]
        if (array_key_exists($nestedDotted, $formData)) {
            return $formData[$nestedDotted];
        }

        // 4) Fallback via Arr::get
        return Arr::get($formData, $nestedDotted);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->formDataWithPermissions = $data;

        return [
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
        ];
    }

    protected function afterCreate(): void
    {
        // Use full form state as source of truth for permission toggles
        $formData = array_merge($this->form->getState(), $this->formDataWithPermissions);

        $resources = collect(glob(app_path('Filament/Resources/*.php')))
            ->map(function ($file) {
                return 'App\\Filament\\Resources\\' . basename($file, '.php');
            })
            ->filter(function ($class) {
                if (!class_exists($class) || $class === \App\Filament\Resources\BaseResource::class)
                    return false;

                // Exclude Help resources
                if (in_array($class, [
                    \App\Filament\Resources\HelpArticleResource::class,
                    \App\Filament\Resources\HelpCategoryResource::class,
                    \App\Filament\Resources\HelpTagResource::class,
                ]))
                    return false;

                $traits = class_uses_recursive($class);
                return in_array(\App\Traits\HasResourcePermissions::class, $traits) || is_subclass_of($class, \App\Filament\Resources\BaseResource::class);
            });

        $allPermissions = [];
        foreach ($resources as $resourceClass) {
            $permissions = $resourceClass::getResourcePermissions();
            foreach ($permissions as $permissionName) {
                $allPermissions[] = $permissionName;
            }
        }

        $permissionsToSync = [];

        foreach ($allPermissions as $permissionName) {
            $parts = explode('.', $permissionName);
            if (count($parts) !== 2) {
                continue;
            }

            $resourceName = $parts[0];
            $action = $parts[1];
            $formValue = $this->resolveToggleState($formData, $resourceName, $action);
            $isEnabled = $this->isEnabledState($formValue);

            if ($isEnabled) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web'
                ]);
                $permissionsToSync[] = $permissionName;
            }
        }

        try {
            $this->record->syncPermissions($permissionsToSync);
        } catch (\Exception $e) {
            throw $e;
        }

        Log::info('Role create permissions debug', [
            'role_id' => $this->record?->id,
            'role' => $this->record?->name,
            'form_keys' => array_keys($formData),
            'booking_payload' => $formData['booking'] ?? null,
            'city_payload' => $formData['city'] ?? null,
            'permissions_to_sync_count' => count($permissionsToSync),
            'permissions_to_sync_preview' => array_slice($permissionsToSync, 0, 30),
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forget("spatie.permission.cache.role.{$this->record->id}");

        // Clear permission cache for all users that have this role
        $userIds = \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->where('role_id', $this->record->id)
            ->where('model_type', \App\Models\User::class)
            ->pluck('model_id');
        foreach ($userIds as $userId) {
            \App\Filament\Resources\BaseResource::clearPermissionCacheForUser((int) $userId);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
