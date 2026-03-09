<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $currentRole = is_string($user?->role) ? $user->role : $user?->role?->value;

        if (! $user || ! in_array($currentRole, $roles, true)) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'You do not have permission to access this resource.',
                    'details' => [],
                ],
                'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            ], 403);
        }

        return $next($request);
    }
}
