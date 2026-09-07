<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;

class CacheAuthenticatedUser
{
    
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user) {
            $cacheKey = 'auth:web_user:' . $user->id;

            $cached = Cache::remember($cacheKey, 60, function () use ($user) {

                return User::query()
                    ->select(['id', 'name', 'email', 'role_id', 'status'])
                    ->find($user->id);
            });

            if ($cached) {
                Auth::setUser($cached);
                $request->setUserResolver(function () use ($cached) {
                    return $cached;
                });
            }
        }

        return $next($request);
    }
}
