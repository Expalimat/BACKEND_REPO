<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OessApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $data = $response->getData(true);
        if (is_array($data) && ($data['success'] ?? false) === true && array_key_exists('data', $data)) {
            return $response;
        }

        $response->setData([
            'success' => true,
            'message' => 'Request completed successfully.',
            'data' => $data,
        ]);

        return $response;
    }
}
