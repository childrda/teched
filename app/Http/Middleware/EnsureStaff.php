<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Teachers and admins only. Students receive 403.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || (! $user->isTeacher() && ! $user->isAdmin())) {
            abort(403);
        }

        return $next($request);
    }
}
