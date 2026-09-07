<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, $module, $action)
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        if (!auth()->user()->hasModulePermission($module, $action)) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
