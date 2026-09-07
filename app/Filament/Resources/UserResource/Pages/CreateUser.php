<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\Pages\CreateRecord;


class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ?string $roleNameToAssign = null;

    public ?string $selectedRole = 'admin'; // Default to admin

    public function mount(): void
    {
        parent::mount();

        $this->selectedRole = 'admin';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {


        $roleName = $data['role'] ?? $data['role_name'] ?? $this->selectedRole ?? null;



        if ($roleName) {

            $role = \Spatie\Permission\Models\Role::where('guard_name', 'web')
                ->where('name', $roleName)
                ->first();

            

            if ($role) {


                $data['role_id'] = UserResource::mapRoleNameToRoleId($role->name);
                $this->roleNameToAssign = $role->name; // Use the exact role name from database

                
            } else {

                $normalizedRoleName = strtolower(trim($roleName));
                $role = \Spatie\Permission\Models\Role::where('guard_name', 'web')
                    ->where(function ($query) use ($normalizedRoleName) {
                        $query->whereRaw('LOWER(name) = ?', [$normalizedRoleName])
                            ->orWhereRaw('LOWER(REPLACE(name, "_", " ")) = ?', [$normalizedRoleName])
                            ->orWhereRaw('LOWER(REPLACE(name, " ", "_")) = ?', [str_replace(' ', '_', $normalizedRoleName)]);
                    })
                    ->first();

                if ($role) {
                    $data['role_id'] = UserResource::mapRoleNameToRoleId($role->name);
                    $this->roleNameToAssign = $role->name;

                    
                } else {


                    $this->roleNameToAssign = $roleName;

                    $data['role_id'] = UserResource::mapRoleNameToRoleId($roleName);

                    
                }
            }
        } else {


            if (isset($data['role_id'])) {
                
            } else {


                $data['role_id'] = 1;

                $adminRole = \Spatie\Permission\Models\Role::where('guard_name', 'web')
                    ->where('name', 'admin')
                    ->first();
                if ($adminRole) {
                    $this->roleNameToAssign = 'admin';
                }
            }
        }

        if (!isset($data['role_id'])) {

            $data['role_id'] = 1;
        }

        unset($data['role_name'], $data['role']);

        return $data;
    }

    protected function afterCreate(): void
    {

        if (isset($this->roleNameToAssign)) {

            $roleNameToFind = $this->roleNameToAssign;

            $role = \Spatie\Permission\Models\Role::where('guard_name', 'web')
                ->where('name', $roleNameToFind)
                ->first();

            

            if ($role) {

                $this->record->syncRoles([]);

                $this->record->assignRole($role);

            } else {

                $role = \Spatie\Permission\Models\Role::create([
                    'name' => $roleNameToFind,
                    'guard_name' => 'web'
                ]);
                $this->record->syncRoles([]);
                $this->record->assignRole($role);

            }

            $this->record->refresh();
            $finalRoles = $this->record->getRoleNames()->toArray();

            \App\Filament\Resources\BaseResource::clearPermissionCacheForUser($this->record->id);
        }
    }
}
