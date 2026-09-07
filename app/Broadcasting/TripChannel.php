<?php

namespace App\Broadcasting;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class TripChannel
{
    
    public function join(User $user, Booking $booking): bool
    {

        return $user->id === $booking->user_id || $user->id === $booking->driver_id;
    }

    
    public static function channelName(Booking $booking): string
    {
        return "trip.{$booking->id}";
    }

    
    public static function presenceChannelName(Booking $booking): string
    {
        return "trip.{$booking->id}";
    }
}


