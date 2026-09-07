<?php

namespace App\Providers;

use App\Auth\CaseSensitiveUserProvider;
use App\Models\Booking;
use App\Models\RideType;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\BookingPolicy;
use App\Policies\RideTypePolicy;
use App\Policies\UserPolicy;
use App\Policies\VehiclePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        User::class => UserPolicy::class,
        Booking::class => BookingPolicy::class,
        RideType::class => RideTypePolicy::class,
        Vehicle::class => VehiclePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Auth::provider('case_sensitive_eloquent', function ($app, array $config) {
            return new CaseSensitiveUserProvider($app['hash'], $config['model']);
        });
    }
}
