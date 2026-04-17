<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && method_exists($user, 'isClient') && $user->isClient()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403);
        }

        return redirect()->route('dashboard');
    }
}

