<?php

namespace App\Policies;

use App\Models\RideType;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RideTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'driver']);
    }

    public function view(User $user, RideType $rideType): bool
    {
        return in_array($user->role, ['admin', 'driver']);
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, RideType $rideType): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, RideType $rideType): bool
    {
        return $user->role === 'admin';
    }

    public function restore(User $user, RideType $rideType): bool
    {
        return $user->role === 'admin';
    }

    public function forceDelete(User $user, RideType $rideType): bool
    {
        return $user->role === 'admin';
    }
}
