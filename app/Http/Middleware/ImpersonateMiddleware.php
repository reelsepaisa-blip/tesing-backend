<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImpersonateMiddleware
{
    
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('impersonate')) {

            view()->share('impersonating', true);
        }

        return $next($request);
    }
}
