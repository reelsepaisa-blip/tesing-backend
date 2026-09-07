<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class DriverChannel
{
    
    public function join(User $user, int $driverId): bool
    {

        return $user->id === $driverId && $user->hasRole('driver');
    }

    
    public static function channelName(User $driver): string
    {
        return "driver.{$driver->id}";
    }

    
    public static function channelNameById(int $driverId): string
    {
        return "driver.{$driverId}";
    }
}


