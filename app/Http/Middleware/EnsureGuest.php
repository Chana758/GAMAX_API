<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuest
{
    /**
     * Block already-authenticated (Bearer token) users from guest-only routes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('sanctum')) {
            return response()->json([
                'success' => false,
                'message' => 'You are already logged in.',
            ], 403);
        }

        return $next($request);
    }
}