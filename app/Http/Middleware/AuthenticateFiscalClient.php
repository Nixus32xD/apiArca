<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFiscalClient
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredTokens = config('fiscal.api_tokens', []);

        if ($configuredTokens === [] && app()->environment('testing')) {
            return $next($request);
        }

        if ($configuredTokens === []) {
            return $this->deny('Fiscal API authentication is not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $providedToken = $request->bearerToken() ?: $request->header('X-Fiscal-Token');

        if (! is_string($providedToken) || $providedToken === '') {
            return $this->deny('Missing fiscal API token.', Response::HTTP_UNAUTHORIZED);
        }

        if (! $this->matchesAnyConfiguredToken($providedToken, $configuredTokens)) {
            return $this->deny('Invalid fiscal API token.', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $configuredTokens
     */
    private function matchesAnyConfiguredToken(string $providedToken, array $configuredTokens): bool
    {
        $providedHash = hash('sha256', $providedToken);

        foreach ($configuredTokens as $configuredToken) {
            if (str_starts_with($configuredToken, 'sha256:')) {
                if (hash_equals(substr($configuredToken, 7), $providedHash)) {
                    return true;
                }

                continue;
            }

            if (hash_equals($configuredToken, $providedToken)) {
                return true;
            }
        }

        return false;
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], $status);
    }
}
