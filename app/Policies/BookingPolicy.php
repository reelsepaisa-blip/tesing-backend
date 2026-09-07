<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BookingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'driver']);
    }

    public function view(User $user, Booking $booking): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'driver') {
            return $booking->driver_id === $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, Booking $booking): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'driver') {
            return $booking->driver_id === $user->id && in_array($booking->status, ['accepted', 'arrived', 'started']);
        }

        return false;
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $user->role === 'admin';
    }

    public function restore(User $user, Booking $booking): bool
    {
        return $user->role === 'admin';
    }

    public function forceDelete(User $user, Booking $booking): bool
    {
        return $user->role === 'admin';
    }
}
