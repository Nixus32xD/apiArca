<?php

namespace App\Services\Fiscal\Support;

use App\Models\FiscalApiLog;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use Throwable;

class FiscalApiLogger
{
    /**
     * @param  array<string, mixed>|string|null  $request
     * @param  array<string, mixed>|string|null  $response
     */
    public function outbound(
        string $operation,
        string $endpoint,
        float $startedAt,
        array|string|null $request,
        array|string|null $response,
        ?int $statusCode,
        ?Throwable $exception = null,
        ?FiscalCompany $company = null,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): void {
        try {
            FiscalApiLog::query()->create([
                'fiscal_company_id' => $company?->id,
                'fiscal_document_id' => $document?->id,
                'direction' => 'outbound',
                'operation' => $operation,
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'request_summary' => $this->summarize($this->sanitize($request)),
                'response_summary' => $this->summarize($this->sanitize($response)),
                'error_message' => $exception?->getMessage(),
                'trace_id' => $traceId,
            ]);
        } catch (Throwable) {
            //
        }
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
