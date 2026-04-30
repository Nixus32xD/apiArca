<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCaea;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAttempt;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use App\Services\Fiscal\Support\ArcaErrorMapper;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

class FiscalCaeaService
{
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly TokenCacheService $tokenCache,
        private readonly Wsfev1Client $wsfev1,
        private readonly FiscalWsfeRequestBuilder $requestBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function request(FiscalCompany $company, string $period, int $order, ?string $traceId = null): array
    {
        $this->credentialStore->activeFor($company);
        $ticket = $this->tokenCache->get($company);

        $response = $this->wsfev1->requestCaea($company, $ticket, $period, $order, null, $traceId);
        $this->persistGrant($company, $period, $order, $response, [
            'operation' => 'FECAEASolicitar',
            'trace_id' => $traceId,
        ]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function consult(FiscalCompany $company, string $period, int $order, ?string $traceId = null): array
    {
        $this->credentialStore->activeFor($company);
        $ticket = $this->tokenCache->get($company);

        $response = $this->wsfev1->consultCaea($company, $ticket, $period, $order, null, $traceId);
        $this->persistGrant($company, $period, $order, $response, [
            'operation' => 'FECAEAConsultar',
            'trace_id' => $traceId,
        ]);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{document: FiscalDocument, response: array<string, mixed>}
     */
    public function report(FiscalDocument $document, array $request, ?string $traceId = null): array
    {
        $document->loadMissing('company');
        $this->credentialStore->activeFor($document->company);
        $ticket = $this->tokenCache->get($document->company);
        $startedAt = microtime(true);
        $attempt = $this->createAttempt($document, 'FECAEARegInformativo', $traceId, $request);

        try {
            $response = $this->wsfev1->reportCaea($document->company, $ticket, $request, $document, $traceId);
            $this->finishAttempt($attempt, 'completed', $startedAt, response: $response);

            return [
                'document' => $this->persistReportResult($document, $request, $response),
                'response' => $response,
            ];
        } catch (Throwable $exception) {
            $this->finishAttempt(
                $attempt,
                'failed',
                $startedAt,
                errorCode: $exception instanceof FiscalException ? $exception->errorCode() : 'unexpected_error',
                errorMessage: $exception->getMessage(),
            );

            throw $exception;
        }
    }

    /**
     * @return array{document: FiscalDocument, response: array<string, mixed>}
     */
    public function reportDocument(FiscalDocument $document, ?string $traceId = null): array
    {
        $caea = $document->authorization_code;

        if ($document->authorization_type !== 'CAEA' || ! is_string($caea) || $caea === '') {
            throw new FiscalException('Document has no CAEA assigned.', 409, 'document_without_caea');
        }

        return $this->report($document, $this->requestBuilder->caea($document, $caea), $traceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function informWithoutMovement(
        FiscalCompany $company,
        string $caea,
        int $pointOfSale,
        int $voucherType,
        ?string $traceId = null,
    ): array {
        $this->credentialStore->activeFor($company);
        $ticket = $this->tokenCache->get($company);

        return $this->wsfev1->informCaeaWithoutMovement($company, $ticket, $caea, $pointOfSale, $voucherType, $traceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function consultWithoutMovement(
        FiscalCompany $company,
        string $caea,
        int $pointOfSale,
        int $voucherType,
        ?string $traceId = null,
    ): array {
        $this->credentialStore->activeFor($company);
        $ticket = $this->tokenCache->get($company);

        return $this->wsfev1->consultCaeaWithoutMovement($company, $ticket, $caea, $pointOfSale, $voucherType, $traceId);
    }

    /**
     * @return array{reported:int, failed:int, without_movement:bool}
     */
    public function reportGrant(FiscalCaea $caea, ?string $traceId = null): array
    {
        $caea->loadMissing('company');
        $reported = 0;
        $failed = 0;

        $documents = $caea->company
            ->documents()
            ->where('authorization_type', 'CAEA')
            ->where('authorization_code', $caea->code)
            ->whereIn('fiscal_status', ['pending_report', 'uncertain'])
            ->get();

        foreach ($documents as $document) {
            try {
                $result = $this->reportDocument($document, $traceId);
                $reported += $result['document']->fiscal_status === 'reported' ? 1 : 0;
            } catch (Throwable) {
                $failed++;
            }
        }

        $withoutMovement = false;

        if ($documents->isEmpty() && $caea->without_movement_reported_at === null) {
            $pointOfSale = $caea->point_of_sale ?? $caea->company->default_point_of_sale;
            $voucherType = $caea->voucher_type ?? $caea->company->default_voucher_type;

            if ($pointOfSale && $voucherType) {
                $this->informWithoutMovement($caea->company, $caea->code, $pointOfSale, $voucherType, $traceId);
                $withoutMovement = true;

                $caea->forceFill([
                    'without_movement_reported_at' => now(),
                ])->save();
            }
        }

        $caea->forceFill([
            'report_status' => $failed > 0 ? FiscalCaea::STATUS_PARTIAL : FiscalCaea::STATUS_REPORTED,
            'reported_at' => $failed > 0 ? null : now(),
        ])->save();

        return [
            'reported' => $reported,
            'failed' => $failed,
            'without_movement' => $withoutMovement,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    private function persistReportResult(FiscalDocument $document, array $request, array $response): FiscalDocument
    {
        $detail = $this->firstItem(data_get($response, 'FeDetResp.FECAEADetResponse', []));
        $errors = $this->messages(data_get($response, 'Errors.Err', []));
        $events = $this->messages(data_get($response, 'Events.Evt', []));
        $observations = $this->messages(data_get($detail, 'Observaciones.Obs', []));
        $result = (string) (data_get($detail, 'Resultado') ?: data_get($response, 'Resultado') ?: data_get($response, 'FeCabResp.Resultado', ''));
        $caea = $this->caeaFromReportRequest($request) ?: $this->stringOrNull(data_get($detail, 'CAEA')) ?: $document->authorization_code;
        $status = match ($result) {
            'A' => 'authorized',
            'R' => 'rejected',
            default => 'uncertain',
        };

        $document->forceFill([
            'status' => $status,
            'fiscal_status' => $result === 'A' ? 'reported' : $this->fiscalStatus($status),
            'authorization_type' => 'CAEA',
            'authorization_code' => $caea,
            'request_payload' => $request,
            'response_payload' => $response,
            'raw_request' => $request,
            'raw_response' => $response,
            'error_code' => data_get($errors, '0.code') ?? data_get($observations, '0.code'),
            'error_message' => data_get($errors, '0.message') ?? data_get($observations, '0.message'),
            'observations' => [
                'observations' => $observations,
                'events' => $events,
                'errors' => $errors,
            ],
            'processed_at' => now(),
        ])->save();

        $document->events()->create([
            'type' => $result === 'A' ? 'caea_reported' : 'caea_report_finished',
            'message' => 'CAEA informative report finished.',
            'data' => [
                'result' => $result,
                'authorization_code' => $document->authorization_code,
            ],
            'created_at' => now(),
        ]);

        return $document->refresh();
    }

    private function caeaFromReportRequest(array $request): ?string
    {
        $caea = data_get($request, 'CAEA')
            ?: data_get($request, 'FeDetReq.FECAEADetRequest.0.CAEA')
            ?: data_get($request, 'FeDetReq.FECAEADetRequest.CAEA');

        return is_scalar($caea) && $caea !== '' ? (string) $caea : null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $metadata
     */
    private function persistGrant(FiscalCompany $company, string $period, int $order, array $response, array $metadata): ?FiscalCaea
    {
        $result = data_get($response, 'ResultGet', $response);
        $code = $this->stringOrNull(data_get($result, 'CAEA'));

        if ($code === null) {
            return null;
        }

        return $company->caeas()->updateOrCreate(
            [
                'period' => $this->stringOrNull(data_get($result, 'Periodo')) ?? $period,
                'order' => is_numeric(data_get($result, 'Orden')) ? (int) data_get($result, 'Orden') : $order,
            ],
            [
                'code' => $code,
                'point_of_sale' => $company->default_point_of_sale,
                'voucher_type' => $company->default_voucher_type,
                'valid_from' => $this->parseArcaDate(data_get($result, 'FchDesde')),
                'valid_to' => $this->parseArcaDate(data_get($result, 'FchHasta')),
                'due_date' => $this->parseArcaDate(data_get($result, 'FchVigHasta') ?? data_get($result, 'FchVto')),
                'report_deadline' => $this->parseArcaDate(data_get($result, 'FchTopeInf')),
                'response_payload' => $response,
                'metadata' => $metadata,
            ],
        );
    }

    private function parseArcaDate(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        $value = preg_replace('/\D+/', '', (string) $value);

        if (! is_string($value) || strlen($value) !== 8) {
            return null;
        }

        return Carbon::createFromFormat('Ymd', $value)->startOfDay();
    }

    private function fiscalStatus(string $status): string
    {
        return match ($status) {
            'authorized' => 'authorized',
            'rejected' => 'rejected',
            'uncertain' => 'uncertain',
            default => 'failed',
        };
    }

    private function createAttempt(FiscalDocument $document, string $operation, ?string $traceId = null, ?array $request = null): FiscalDocumentAttempt
    {
        return $document->attempts()->create([
            'attempt_number' => ((int) $document->attempts()->max('attempt_number')) + 1,
            'operation' => $operation,
            'status' => 'started',
            'environment' => $document->company->environment,
            'endpoint' => config('fiscal.wsfev1.endpoints.'.$document->company->environment),
            'request_payload' => $request,
            'started_at' => now(),
            'trace_id' => $traceId,
        ]);
    }

    private function finishAttempt(
        FiscalDocumentAttempt $attempt,
        string $status,
        float $startedAt,
        ?array $response = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $attempt->forceFill([
            'status' => $status,
            'response_payload' => $response,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'finished_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function firstItem(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return Arr::first($value, fn ($item) => is_array($item), []);
        }

        return $value;
    }

    /**
     * @return array<int, array{code: string, message: string, arca_code: string|null, arca_message: string|null}>
     */
    private function messages(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        $items = array_is_list($value) ? $value : [$value];

        return array_values(array_filter(array_map(function ($item): ?array {
            if (! is_array($item)) {
                return null;
            }

            return ArcaErrorMapper::mapArcaMessage(
                isset($item['Code']) ? (string) $item['Code'] : null,
                isset($item['Msg']) ? (string) $item['Msg'] : null,
            );
        }, $items)));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }
}
