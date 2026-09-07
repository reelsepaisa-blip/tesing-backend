<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class DemoMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get current user ID (if authenticated)
        $userId = Auth::check() ? Auth::id() : null;

        // If not authenticated, allow request to pass
        if (!$userId) {
            return $next($request);
        }

        // User ID 1 can do everything, so skip restrictions
        if ($userId === 1) {
            return $next($request);
        }

        // Only apply restrictions for User ID 2
        if ($userId !== 2) {
            return $next($request);
        }

        // Allow all API routes to pass through (skip demo mode restrictions for APIs)
        if ($request->is('api/*')) {
            return $next($request);
        }

        // Block destructive HTTP methods
        $method = $request->method();
        $isDestructive = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']);

        // Allow GET and HEAD requests
        if (!$isDestructive) {
            return $next($request);
        }

        // IMPORTANT: Allow ALL Livewire requests through
        // Component-level checks (canCreate/canEdit/canDelete) and model events (PreventsDemoDeletion)
        // will handle blocking destructive actions appropriately
        if ($request->is('livewire/update') || $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        // List of routes/patterns that should be allowed even in demo mode
        $allowedRoutes = [
            'logout',
            'login',
            'password.reset',
            'filament.admin.auth.logout',
        ];

        $currentRoute = $request->route()?->getName();

        // Allow specific routes
        if ($currentRoute && in_array($currentRoute, $allowedRoutes)) {
            return $next($request);
        }

        // Check if it's a logout action
        if ($request->is('*/logout') || $request->is('*/log-out')) {
            return $next($request);
        }

        // Block specific destructive actions
        $blockedActions = [
            'delete',
            'destroy',
            'force-delete',
            'trash',
            'restore',
            'bulk-delete',
        ];

        $action = $request->route()?->getActionMethod();
        $path = $request->path();

        // Check if the action or path contains blocked keywords
        foreach ($blockedActions as $blockedAction) {
            if (
                stripos($action ?? '', $blockedAction) !== false ||
                stripos($path, $blockedAction) !== false ||
                $request->has($blockedAction)
            ) {
                return $this->blockedResponse($request);
            }
        }

        // Block specific critical paths
        $blockedPaths = [
            'admin/system-configurations',
            'admin/settings',
            'admin/users',
            'admin/payment-gateways',
            'admin/mail-settings',
            'admin/api-keys',
        ];

        foreach ($blockedPaths as $blockedPath) {
            if (
                stripos($path, $blockedPath) !== false &&
                $isDestructive
            ) {
                // Special check: if it's settings/system config, always block destructive actions
                return $this->blockedResponse($request);
            }
        }

        // For Filament admin routes, block destructive actions for restricted users
        if ($request->is('admin/*')) {
            // Block DELETE actions always for restricted users (they're always destructive)
            // This is a hard block regardless of Livewire or not
            if ($method === 'DELETE') {
                return $this->blockedResponse($request);
            }

            // For Livewire requests (Filament uses Livewire), allow them to pass
            // Filament resources check canCreate/canEdit/canDelete methods which we've protected
            // The BaseResource methods will block the actions appropriately
            if ($request->hasHeader('X-Livewire') || $request->hasHeader('X-Inertia')) {
                // BaseResource canDelete() will handle blocking delete actions
                return $next($request);
            }

            // For non-Livewire POST/PUT/PATCH to admin routes, block them for restricted users
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                return $this->blockedResponse($request);
            }
        }

        return $next($request);
    }

    /**
     * Return blocked response based on request type
     */
    protected function blockedResponse(Request $request): Response
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        if ($request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return redirect()->back()->with('error', 'You do not have permission to perform this action.');
    }
}
