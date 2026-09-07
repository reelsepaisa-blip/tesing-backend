<?php

namespace App\Traits;

trait HasDemoMode
{
    /**
     * Check if current user is restricted (ID 2)
     */
    protected function isRestrictedUser(): bool
    {
        return auth()->id() === 2;
    }

    /**
     * Disable form fields for restricted users
     * Note: User ID 2 can create and edit, so forms should not be disabled
     */
    protected function disableInDemoMode($form): void
    {
        // User ID 2 can create and edit, so don't disable forms
        // This method is kept for backward compatibility but does nothing for user ID 2
    }

    /**
     * Check if action should be hidden for restricted users (ID 2)
     * Delete restrictions removed - all users can delete
     */
    protected function shouldHideInDemoMode(string $action = 'delete'): bool
    {
        // Delete restrictions removed - return false to allow all actions
        return false;
    }

    /**
     * Get restriction message
     */
    protected function getDemoModeMessage(): string
    {
        return 'You do not have permission to perform this action.';
    }
}
