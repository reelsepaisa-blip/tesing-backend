<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Zone;
use Illuminate\Auth\Access\Response;

class ZonePolicy
{
    
    public function viewAny(User $user): bool
    {

        if ($user->id === 1 || $user->id === 2) {
            return true;
        }

        return $user->hasRole('admin');
    }

    
    public function view(User $user, Zone $zone): bool
    {

        if ($user->id === 1 || $user->id === 2) {
            return true;
        }

        return $user->hasRole('admin');
    }

    
    public function create(User $user): bool
    {

        if ($user->id === 1) {
            return true;
        }

        return $user->hasRole('admin');
    }

    
    public function update(User $user, Zone $zone): bool
    {

        if ($user->id === 1) {
            return true;
        }

        return $user->hasRole('admin');
    }

    
    public function delete(User $user, Zone $zone): bool
    {

        if ($user->id === 1) {
            return true;
        }

        return $user->hasRole('admin');
    }

    
    public function restore(User $user, Zone $zone): bool
    {
        return false;
    }

    
    public function forceDelete(User $user, Zone $zone): bool
    {
        return false;
    }
}
