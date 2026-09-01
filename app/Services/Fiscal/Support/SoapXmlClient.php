<?php

namespace App\Services\Fiscal\Support;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class SoapXmlClient
{
    /** @var list<string> */
    private const NON_RETRYABLE_TRANSPORT_OPERATIONS = [
        'FECAESolicitar',
        'FECAEASolicitar',
        'FECAEARegInformativo',
        'FECAEASinMovimientoInformar',
    ];

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
        array $options = [],
    ): array|string {
        $envelope = $this->envelope($operation, $namespace, $bodyXml);
        $startedAt = microtime(true);
        $statusCode = null;
        $profile = $options['profile'] ?? null;
        $soapOptions = $this->resolveSoapOptions($profile);
        $transportRetryEnabled = $this->transportRetryAllowed($operation) && $soapOptions['retry_times'] > 1;

        try {
            $request = Http::withOptions([
                'curl' => [
                    CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                ],
            ])
                ->timeout($soapOptions['timeout'])
                ->connectTimeout($soapOptions['connect_timeout'])
                ->withHeaders(array_filter([
                    'SOAPAction' => $soapAction,
                    'Content-Type' => 'text/xml; charset=utf-8',
                ], fn ($value) => $value !== null))
                ->withBody($envelope, 'text/xml; charset=utf-8');

            if ($transportRetryEnabled) {
                $request = $this->withTransportRetry($request, $soapOptions);
            }

            $response = $request->post($endpoint);

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
                throw new FiscalException(
                    ArcaErrorMapper::messageForHttpStatus($statusCode),
                    $statusCode === 504 ? 504 : 502,
                    'arca_http_error',
                    [
                        'operation' => $operation,
                        'status_code' => $statusCode,
                        'endpoint' => $endpoint,
                        'soap_profile' => $profile,
                        'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                    ]
                );
            }

            return $this->xmlParser->firstNode($responseBody, $resultNode);
        } catch (RequestException $exception) {
            $response = $exception->response;
            $statusCode = $response->status();
            $responseBody = $response->body();

            $this->logger->outbound(
                $operation,
                $endpoint,
                $startedAt,
                $this->safeRequestSummary($operation, $bodyXml),
                $responseBody,
                $statusCode,
                $exception,
                $company,
                $document,
                $traceId,
            );

            // ARCA returns SOAP faults with HTTP 500; preserve the fiscal cause.
            $this->xmlParser->firstNode($responseBody, $resultNode);

            throw new FiscalException(
                ArcaErrorMapper::messageForHttpStatus($statusCode),
                $statusCode === 504 ? 504 : 502,
                'arca_http_error',
                [
                    'operation' => $operation,
                    'status_code' => $statusCode,
                    'endpoint' => $endpoint,
                    'soap_profile' => $profile,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                ],
                $exception
            );
        } catch (ConnectionException $exception) {

            $elapsed = round(microtime(true) - $startedAt, 3);

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

            throw new FiscalException(
                'Timeout al conectar con ARCA durante la operación '.$operation.'. '
                    .'No se recibió respuesta dentro del tiempo configurado.',
                504,
                'arca_timeout',
                [
                    'operation' => $operation,
                    'endpoint' => $endpoint,
                    'soap_profile' => $profile,
                    'timeout_seconds' => $soapOptions['timeout'],
                    'connect_timeout_seconds' => $soapOptions['connect_timeout'],
                    'retry_times_configured' => $soapOptions['retry_times'],
                    'transport_retry_enabled' => $transportRetryEnabled,
                    'retry_sleep_ms' => $soapOptions['retry_sleep_ms'],
                    'elapsed_seconds' => $elapsed,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'trace_id' => $traceId,
                    'company_id' => $company?->id,
                    'document_id' => $document?->id,
                ],
                $exception
            );
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

            throw new FiscalException(
                ArcaErrorMapper::messageFor('arca_unexpected_error'),
                502,
                'arca_unexpected_error',
                [
                    'operation' => $operation,
                    'endpoint' => $endpoint,
                    'soap_profile' => $profile,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
                $exception
            );
        }
    }

    /** @param array{timeout:int,connect_timeout:int,retry_times:int,retry_sleep_ms:int} $soapOptions */
    private function withTransportRetry(PendingRequest $request, array $soapOptions): PendingRequest
    {
        return $request->retry(
            $soapOptions['retry_times'],
            $soapOptions['retry_sleep_ms'],
            fn ($exception): bool => $exception instanceof ConnectionException,
        );
    }

    public function transportRetryAllowed(string $operation): bool
    {
        return ! in_array($operation, self::NON_RETRYABLE_TRANSPORT_OPERATIONS, true);
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
            'body' => preg_replace('/<(Token|Sign|in0)>.*?<\/\1>/is', '<$1>[redacted]</$1>', $bodyXml) ?? $bodyXml,
        ];
    }

    /**
     * @return array{timeout:int,connect_timeout:int,retry_times:int,retry_sleep_ms:int}
     */
    private function resolveSoapOptions(?string $profile): array
    {
        $global = config('fiscal.soap', []);
        $operation = is_string($profile) && $profile !== ''
            ? config('fiscal.soap.operations.'.$profile, [])
            : [];

        return [
            'timeout' => max(1, (int) ($operation['timeout'] ?? $global['timeout'] ?? 30)),
            'connect_timeout' => max(1, (int) ($operation['connect_timeout'] ?? $global['connect_timeout'] ?? 10)),
            'retry_times' => max(1, (int) ($operation['retry_times'] ?? $global['retry_times'] ?? 2)),
            'retry_sleep_ms' => max(0, (int) ($operation['retry_sleep_ms'] ?? $global['retry_sleep_ms'] ?? 1000)),
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
