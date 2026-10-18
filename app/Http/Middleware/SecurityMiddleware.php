<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // API always answers JSON (never an HTML error page)
        $request->headers->set('Accept', 'application/json');

        // strip HTML tags + null bytes from every text input (passwords are never touched)
        $body = $request->isJson() ? $request->json() : $request->request;
        $body->replace($this->clean($body->all()));
        $request->query->replace($this->clean($request->query->all()));

        $response = $next($request);

        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');
        $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $h->set('Cache-Control', 'no-store');
        if (app()->environment('production')) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        header_remove('X-Powered-By');

        return $response;
    }

    private function clean(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->clean($v, (string) $k);
            }
            return $value;
        }
        if (! is_string($value) || stripos($key, 'password') !== false) {
            return $value;
        }
        return trim(strip_tags(str_replace("\0", '', $value)));
    }
}
