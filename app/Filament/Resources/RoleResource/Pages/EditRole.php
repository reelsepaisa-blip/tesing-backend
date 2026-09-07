<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class EditRole extends EditRecord
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

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->action(function ($record) {
                    // Block deletion for restricted users (ID 2)
                    $userId = auth()->id();
                    if ($userId === 2) {
                        \Filament\Notifications\Notification::make()
                            ->title('Access Restricted')
                            ->body('In demo mode you are not deleting data...')
                            ->danger()
                            ->persistent()
                            ->send();
                        return false;
                    }

                    // Proceed with normal deletion
                    $record->delete();

                    \Filament\Notifications\Notification::make()
                        ->title('Deleted')
                        ->body('The role has been deleted.')
                        ->success()
                        ->send();

                    return redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $role = $this->record;
        $rolePermissions = $role->permissions->pluck('name')->toArray();

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

        foreach ($resources as $resourceClass) {
            $resourceName = $resourceClass::getPermissionResourceName();
            $permissions = $resourceClass::getResourcePermissions();

            $allChecked = true;
            foreach ($permissions as $action => $permissionName) {
                $hasPermission = in_array($permissionName, $rolePermissions);

                if (!$hasPermission) {
                    // Try "clean" match (strip _resource)
                    $cleanName = str_replace('_resource.', '.', $permissionName);
                    $hasPermission = in_array($cleanName, $rolePermissions);
                }

                if (!$hasPermission) {
                    // Try resource suffix match
                    $resourceSuffixName = str_replace('.', '_resource.', $permissionName);
                    $hasPermission = in_array($resourceSuffixName, $rolePermissions);
                }

                if (!$hasPermission) {
                    // Try plural match
                    $parts = explode('.', str_replace('_resource.', '.', $permissionName));
                    if (count($parts) === 2) {
                        $pluralName = Str::plural($parts[0]) . '.' . $parts[1];
                        $hasPermission = in_array($pluralName, $rolePermissions);

                        if (!$hasPermission) {
                            // Try plural with resource suffix
                            $pluralResourceName = Str::plural($parts[0]) . '_resource.' . $parts[1];
                            $hasPermission = in_array($pluralResourceName, $rolePermissions);
                        }
                    }
                }

                // Special mapping for promo_code -> promos
                if (!$hasPermission && Str::startsWith($permissionName, 'promo_code.')) {
                    $promoName = str_replace('promo_code.', 'promos.', $permissionName);
                    $hasPermission = in_array($promoName, $rolePermissions);
                }

                $data[$resourceName][$action] = $hasPermission;
                if (!$hasPermission) {
                    $allChecked = false;
                }
            }
            $data["{$resourceName}_all"] = $allChecked;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->formDataWithPermissions = $data;

        return [
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
        ];
    }

    protected function afterSave(): void
    {
        // Use full form state as source of truth (getState() has all toggles; mutateFormDataBeforeSave may only receive model fillable)
        $formData = array_merge($this->form->getState(), $this->formDataWithPermissions);

        $resources = collect(glob(app_path('Filament/Resources/*.php')))
            ->map(function ($file) {
                return 'App\\Filament\\Resources\\' . basename($file, '.php');
            })
            ->filter(function ($class) {
                if (!class_exists($class))
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

        Log::info('Role edit permissions debug', [
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

        // Clear permission cache for all users that have this role so they get updated access
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
