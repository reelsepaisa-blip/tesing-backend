<?php

namespace App\Policies;

use App\Models\City;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CityPolicy
{

    public function before(User $user, string $ability): bool|null
    {
        if ((int) $user->role_id === 1) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return in_array((int) $user->role_id, [1, 4]); // Admin or Support
    }


    public function view(User $user, City $city): bool
    {
        return in_array((int) $user->role_id, [1, 4]); // Admin or Support
    }


    public function create(User $user): bool
    {
        return (int) $user->role_id === 1; // Admin only for now
    }


    public function update(User $user, City $city): bool
    {
        return (int) $user->role_id === 1;
    }


    public function delete(User $user, City $city): bool
    {
        return (int) $user->role_id === 1;
    }


    public function restore(User $user, City $city): bool
    {
        return (int) $user->role_id === 1;
    }


    public function forceDelete(User $user, City $city): bool
    {
        return false; // Never allow permanent deletion
    }


    public function manageZones(User $user, City $city): bool
    {
        return (int) $user->role_id === 1;
    }


    public function manageRideTypes(User $user, City $city): bool
    {
        return (int) $user->role_id === 1;
    }
}
