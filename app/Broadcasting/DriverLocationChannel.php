<?php

namespace App\Broadcasting;

use App\Models\Booking;
use App\Models\User;

class DriverLocationChannel
{
    public function join(User $user, Booking $booking): bool
    {

        return $user->id === $booking->user_id || $user->id === $booking->driver_id;
    }
}








