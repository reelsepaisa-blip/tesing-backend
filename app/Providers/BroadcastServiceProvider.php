<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    
    public function boot(): void
    {

        Broadcast::routes(['middleware' => ['web', 'auth']]);

        Broadcast::channel('drivers.city.{cityId}', function ($user, $cityId) {

            return true;
        });

        Broadcast::channel('drivers.ride_type.{rideTypeId}', function ($user, $rideTypeId) {

            return true;
        });

        Broadcast::channel('drivers.all', function ($user) {

            return true;
        });

        Broadcast::channel('driver.{driverId}', function ($user, $driverId) {

            return true;
        });

        Broadcast::channel('flutter-roundtrip-channel', function ($user) {

            return true;
        });

        Broadcast::channel('user.all', function ($user) {

            return true;
        });

        Broadcast::channel('support.user.{userId}', function ($user, $userId) {

            return true;
        });

        Broadcast::channel('support.admin.{adminId}', function ($user, $adminId) {

            return true;
        });

        Broadcast::channel('support.admins', function ($user) {

            return true;
        });

        Broadcast::channel('support.booking.{bookingId}', function ($user, $bookingId) {

            return true;
        });
    }
}
