<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// A deactivated account loses access immediately, even if it still holds a valid token.
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->status !== 'Active') {
            $user->tokens()->delete();
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }
        return $next($request);
    }
}
