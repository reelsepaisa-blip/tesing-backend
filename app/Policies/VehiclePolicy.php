<?php

namespace App\Policies;

use App\Models\Vehicle;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class VehiclePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'driver']);
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'driver') {
            return $vehicle->driver_id === $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'driver']);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'driver') {
            return $vehicle->driver_id === $user->id;
        }

        return false;
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->role === 'admin';
    }

    public function restore(User $user, Vehicle $vehicle): bool
    {
        return $user->role === 'admin';
    }

    public function forceDelete(User $user, Vehicle $vehicle): bool
    {
        return $user->role === 'admin';
    }
}
