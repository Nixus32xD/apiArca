<?php

namespace App\Services\Fiscal\Support;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SoapXmlClient
{
    public function __construct(
        private readonly XmlParser $xmlParser,
        private readonly FiscalApiLogger $logger,
    ) {}

    /**
     * @return array<string, mixed>|string
     */
    public function call(
        string $endpoint,
        string $operation,
        string $namespace,
        string $bodyXml,
        string $resultNode,
        ?string $soapAction = null,
        ?FiscalCompany $company = null,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): array|string {
        $envelope = $this->envelope($operation, $namespace, $bodyXml);
        $startedAt = microtime(true);
        $statusCode = null;

        try {
            $response = Http::timeout((int) config('fiscal.soap.timeout', 60))
                ->connectTimeout((int) config('fiscal.soap.connect_timeout', 15))
                ->withHeaders(array_filter([
                    'SOAPAction' => $soapAction,
                    'Content-Type' => 'text/xml; charset=utf-8',
                ], fn ($value) => $value !== null))
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->post($endpoint);

            $statusCode = $response->status();
            $responseBody = $response->body();

            $this->logger->outbound(
                $operation,
                $endpoint,
                $startedAt,
                $this->safeRequestSummary($operation, $bodyXml),
                $responseBody,
                $statusCode,
                null,
                $company,
                $document,
                $traceId,
            );

            if (! $response->successful()) {
                throw new FiscalException(ArcaErrorMapper::messageForHttpStatus($statusCode), $statusCode === 504 ? 504 : 502, 'arca_http_error', [
                    'operation' => $operation,
                    'status_code' => $statusCode,
                ]);
            }

            return $this->xmlParser->firstNode($responseBody, $resultNode);
        } catch (ConnectionException $exception) {
            $this->logger->outbound(
                $operation,
                $endpoint,
                $startedAt,
                $this->safeRequestSummary($operation, $bodyXml),
                null,
                $statusCode,
                $exception,
                $company,
                $document,
                $traceId,
            );

            throw new FiscalException(ArcaErrorMapper::messageFor('arca_timeout'), 504, 'arca_timeout', [
                'operation' => $operation,
            ], $exception);
        } catch (FiscalException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->outbound(
                $operation,
                $endpoint,
                $startedAt,
                $this->safeRequestSummary($operation, $bodyXml),
                null,
                $statusCode,
                $exception,
                $company,
                $document,
                $traceId,
            );

            throw new FiscalException(ArcaErrorMapper::messageFor('arca_unexpected_error'), 502, 'arca_unexpected_error', [
                'operation' => $operation,
            ], $exception);
        }
    }

    private function envelope(string $operation, string $namespace, string $bodyXml): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            .'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
            .'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'
            .'<'.$operation.' xmlns="'.$this->escape($namespace).'">'
            .$bodyXml
            .'</'.$operation.'>'
            .'</soap:Body>'
            .'</soap:Envelope>';
    }

    /**
     * @return array<string, string>
     */
    private function safeRequestSummary(string $operation, string $bodyXml): array
    {
        return [
            'operation' => $operation,
            'body' => preg_replace('/<(Token|Sign)>.*?<\/\1>/s', '<$1>[redacted]</$1>', $bodyXml) ?? $bodyXml,
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
