<?php

namespace App\Traits;


use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Filament\Notifications\Notification;

trait PreventsDemoDeletion
{
    /**
     * Boot the trait - all delete restrictions have been removed
     * This method now does nothing to avoid any performance issues or errors
     */
    protected static function bootPreventsDemoDeletion(): void
    {
        // All delete restrictions removed - method left empty to maintain trait compatibility
        // No restrictions are applied, all users can delete records
        return;
    }
}
