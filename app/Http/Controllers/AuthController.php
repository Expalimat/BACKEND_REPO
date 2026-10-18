<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    private const MAX_PER_ACCOUNT = 5;    // wrong passwords for ONE username
    private const MAX_PER_IP = 20;        // wrong passwords from ONE IP (any usernames)
    private const LOCK_SECONDS = 900;     // 15 minutes
    private const TOKEN_HOURS = 8;

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $username = strtolower($data['username']);
        $ip = Oess::clientIp($request);
        $accountKey = 'login:acct:' . $username;
        $ipKey = 'login:ip:' . $ip;

        foreach ([[$accountKey, self::MAX_PER_ACCOUNT], [$ipKey, self::MAX_PER_IP]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = RateLimiter::availableIn($key);
                return response()->json([
                    'message' => 'Too many failed login attempts. Please try again in ' . (int) ceil($wait / 60) . ' minute(s).',
                ], 429)->header('Retry-After', (string) $wait);
            }
        }

        $user = User::where('username', $username)->first();

        // Always do one hash operation, so response time doesn't reveal which usernames exist
        if ($user) {
            $valid = Hash::check($data['password'], $user->password);
        } else {
            Hash::make($data['password']);
            $valid = false;
        }

        if (! $valid) {
            RateLimiter::hit($accountKey, self::LOCK_SECONDS);
            RateLimiter::hit($ipKey, self::LOCK_SECONDS);
            Log::warning('Failed login', ['username' => $username, 'ip' => $ip]);

            if (RateLimiter::attempts($accountKey) === self::MAX_PER_ACCOUNT) {
                Oess::auditSystem('System', 'Account Locked (Too Many Failed Logins)', 'Security',
                    "Username {$username} locked for 15 minutes after " . self::MAX_PER_ACCOUNT . " failed logins (IP: {$ip})");
            }

            return response()->json(['message' => 'Invalid username or password. Please try again.'], 401);
        }

        if ($user->status !== 'Active') {
            return response()->json(['message' => 'This account has been deactivated. Please contact the administrator.'], 403);
        }

        RateLimiter::clear($accountKey);
        $user->tokens()->where('expires_at', '<', now())->delete();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('oess', ['*'], now()->addHours(self::TOKEN_HOURS))->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out.']);
    }
}
