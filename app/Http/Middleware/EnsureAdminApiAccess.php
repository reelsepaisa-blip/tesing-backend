<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API admin check for high-risk utility routes only: accepts role_id 1 (Filament admin)
 * or Spatie "admin" role. Does not replace role:admin elsewhere in the app.
 */
class EnsureAdminApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? auth()->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $allowed = (int) $user->role_id === 1
            || (method_exists($user, 'hasRole') && $user->hasRole('admin'));

        if (! $allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Admin access required.',
            ], 403);
        }

        return $next($request);
    }
}
