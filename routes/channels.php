<?php

use Illuminate\Support\Facades\Broadcast;

// Private channel authentication
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('driver.{driverId}', function ($user, $driverId) {
    return $user->role === 2 && (int) $user->id === (int) $driverId;
});

Broadcast::channel('booking.{bookingId}', function ($user, $bookingId) {
    // Allow access if user is the customer or driver of the booking
    $booking = \App\Models\Booking::find($bookingId);
    return $booking && (
        $booking->user_id === $user->id ||
        $booking->driver_id === $user->id
    );
});

// Driver channels (all drivers can access)
Broadcast::channel('drivers.all', function ($user) {
    return $user->role === 2; // Only drivers
});

Broadcast::channel('drivers.city.{cityId}', function ($user, $cityId) {
    return $user->role === 2; // Only drivers
});

Broadcast::channel('drivers.ride_type.{rideTypeId}', function ($user, $rideTypeId) {
    return $user->role === 2; // Only drivers
});

// Global channels for all users and drivers
Broadcast::channel('global.all', function ($user) {
    return true; // All authenticated users (users and drivers)
});

Broadcast::channel('global.drivers', function ($user) {
    return $user->role === 2; // Only drivers
});

Broadcast::channel('global.users', function ($user) {
    return $user->role === 1; // Only users (customers)
});

// Additional global channels that might be used
Broadcast::channel('global', function ($user) {
    return true; // All authenticated users
});

// Support Chat Channels
Broadcast::channel('support.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('support.admin.{adminId}', function ($user, $adminId) {
    return $user->role_id === 1 && (int) $user->id === (int) $adminId; // Only admins
});

Broadcast::channel('support.admins', function ($user) {
    return $user->role_id === 1; // Only admins
});

// Public channel for user.all (no authentication required)
Broadcast::channel('user.all', function () {
    // Debug logging for user.all channel authorization
    // Public channel - no authentication required
    return true;
});
