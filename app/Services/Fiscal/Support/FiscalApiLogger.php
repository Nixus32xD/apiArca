<?php

namespace App\Services\Fiscal\Support;

use App\Models\FiscalApiLog;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use Throwable;

class FiscalApiLogger
{
    private const SENSITIVE_XML_FIELD = 'token|sign|in0|certificate|private_key|passphrase|password|secret';

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
                'error_message' => $exception ? $this->sanitizeXmlSecrets($exception->getMessage()) : null,
                'trace_id' => $traceId,
            ]);
        } catch (Throwable) {
            //
        }
    }

    private function sanitize(mixed $payload): mixed
    {
        if (is_string($payload)) {
            return $this->sanitizeXmlSecrets($payload);
        }

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

    private function sanitizeXmlSecrets(string $payload): string
    {
        $field = self::SENSITIVE_XML_FIELD;
        $payload = (string) preg_replace_callback(
            "/<(?<tag>(?:[a-z_][a-z0-9_.-]*:)?(?:{$field}))\\b(?<attributes>[^>]*)>.*?<\\/\\k<tag>\\s*>/is",
            fn (array $match): string => '<'.$match['tag'].$match['attributes'].'>[redacted]</'.$match['tag'].'>',
            $payload,
        );
        $payload = (string) preg_replace_callback(
            "/&lt;(?<tag>(?:[a-z_][a-z0-9_.-]*:)?(?:{$field}))\\b(?<attributes>.*?)&gt;.*?&lt;\\/\\k<tag>\\s*&gt;/is",
            fn (array $match): string => '&lt;'.$match['tag'].$match['attributes'].'&gt;[redacted]&lt;/'.$match['tag'].'&gt;',
            $payload,
        );
        $payload = (string) preg_replace_callback(
            "/(?<name>\\b(?:{$field}))\\s*=\\s*(?<quote>[\"']).*?\\k<quote>/is",
            fn (array $match): string => $match['name'].'='.$match['quote'].'[redacted]'.$match['quote'],
            $payload,
        );

        return (string) preg_replace_callback(
            "/(?<name>\\b(?:{$field}))\\s*=\\s*&quot;.*?&quot;/is",
            fn (array $match): string => $match['name'].'=&quot;[redacted]&quot;',
            $payload,
        );
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
