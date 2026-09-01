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
        $configuredClients = config('fiscal.api_clients', []);

        if ($configuredTokens === [] && $configuredClients === [] && app()->environment('testing')) {
            return $next($request);
        }

        if ($configuredTokens === [] && $configuredClients === []) {
            return $this->deny('Fiscal API authentication is not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $providedToken = $request->bearerToken() ?: $request->header('X-Fiscal-Token');

        if (! is_string($providedToken) || $providedToken === '') {
            return $this->deny('Missing fiscal API token.', Response::HTTP_UNAUTHORIZED);
        }

        $client = $this->matchingClient($providedToken, $configuredClients);
        if ($client !== null) {
            $request->attributes->set('fiscal_client', $client);

            return $next($request);
        }

        if (! $this->matchesAnyConfiguredToken($providedToken, $configuredTokens)) {
            return $this->deny('Invalid fiscal API token.', Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('fiscal_client', ['id' => 'legacy-token', 'external_fiscal_ids' => null]);

        return $next($request);
    }

    /** @param array<int, mixed> $clients */
    private function matchingClient(string $providedToken, array $clients): ?array
    {
        foreach ($clients as $client) {
            if (! is_array($client) || ! isset($client['token']) || ! is_string($client['token'])) {
                continue;
            }

            if (! $this->matchesAnyConfiguredToken($providedToken, [$client['token']])) {
                continue;
            }

            return [
                'id' => (string) ($client['id'] ?? 'unnamed-client'),
                'external_fiscal_ids' => is_array($client['external_fiscal_ids'] ?? null)
                    ? array_values(array_filter($client['external_fiscal_ids'], 'is_scalar'))
                    : [],
            ];
        }

        return null;
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
