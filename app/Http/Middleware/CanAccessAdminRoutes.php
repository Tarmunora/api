<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

class CanAccessAdminRoutes
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()->is_admin && ! $request->user()->game_admin_id) {
            return $request->expectsJson()
                ? abort(403, 'You don\'t have permission to access this route.')
                : Redirect::guest(URL::route('dashboard'));
        }

        return $next($request);
    }
}