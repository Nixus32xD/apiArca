<?php

namespace App\Http\Middleware;

use App\Models\FiscalApiLog;
use App\Models\FiscalCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditFiscalApiRequest
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->log($request, null, $startedAt, $exception);

            throw $exception;
        }

        $this->log($request, $response, $startedAt);

        return $response;
    }

    private function log(Request $request, ?Response $response, float $startedAt, ?Throwable $exception = null): void
    {
        try {
            FiscalApiLog::query()->create([
                'fiscal_company_id' => $this->resolveCompanyId($request),
                'direction' => 'inbound',
                'operation' => $request->method().' '.$request->path(),
                'endpoint' => $request->fullUrl(),
                'status_code' => $response?->getStatusCode() ?? 500,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_summary' => $this->summarize($this->sanitize($request->all())),
                'response_summary' => $this->summarize($this->responsePayload($response)),
                'error_message' => $exception?->getMessage(),
                'trace_id' => $request->header('X-Trace-Id') ?: $request->header('X-Request-Id'),
            ]);
        } catch (Throwable) {
            // Audit logging must never break the fiscal API response path.
        }
    }

    private function resolveCompanyId(Request $request): ?int
    {
        $businessId = $request->input('business_id') ?: $request->input('external_business_id');

        if (! is_scalar($businessId) || $businessId === '') {
            return null;
        }

        return FiscalCompany::query()
            ->where('external_business_id', (string) $businessId)
            ->when(is_numeric($businessId), fn ($query) => $query->orWhereKey((int) $businessId))
            ->value('id');
    }

    private function sanitize(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/certificate|private_key|passphrase|token|sign|password|secret/i', $key)) {
                $sanitized[$key] = '[redacted]';

                continue;
            }

            $sanitized[$key] = $this->sanitize($value);
        }

        return $sanitized;
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function responsePayload(?Response $response): array|string|null
    {
        if ($response === null) {
            return null;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $this->sanitize($decoded) : $content;
    }

    private function summarize(mixed $payload): ?array
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($json)) {
            return null;
        }

        $maxLength = (int) config('fiscal.logging.max_payload_chars', 8000);

        if (strlen($json) > $maxLength) {
            $json = substr($json, 0, $maxLength).'...';
        }

        return ['payload' => $json];
    }
}
