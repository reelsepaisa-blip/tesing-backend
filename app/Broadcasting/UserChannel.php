<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class UserChannel
{
    
    public function join(User $user, int $userId): bool
    {

        return $user->id === $userId;
    }

    
    public static function channelName(User $user): string
    {
        return "user.{$user->id}";
    }

    
    public static function channelNameById(int $userId): string
    {
        return "user.{$userId}";
    }
}


