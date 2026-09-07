<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use App\Filament\Resources\Pages\EditRecord;


class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?string $roleNameToAssign = null;

    public ?string $selectedRole = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->record;
        $roleName = UserResource::getResolvedRoleNameForRecord($record);
        $data['role'] = $roleName;
        $data['role_name'] = $roleName;
        $this->selectedRole = $roleName;
        $data['role_id'] = $record->role_id;
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
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

                    $data['role_id'] = $data['role_id'] ?? $this->record->role_id ?? 1;
                    $this->roleNameToAssign = $roleName;

                    
                }
            }
        } else {

            $data['role_id'] = $data['role_id'] ?? $this->record->role_id ?? 1;
            
        }

        unset($data['role'], $data['role_name']);

        // Prevent current user from setting their own status to non-active (would lock them out of the panel)
        if (auth()->check() && (int) auth()->id() === (int) $this->record->id) {
            $requestedStatus = $data['status'] ?? $this->record->status;
            if ($requestedStatus !== 'active') {
                $data['status'] = 'active';
                Notification::make()
                    ->title('Status not changed')
                    ->body('You cannot set your own status to non-Active, or you would lose access to the admin panel.')
                    ->warning()
                    ->send();
            }
        }

        return $data;
    }

    protected function afterSave(): void
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
