<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAttempt;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class FiscalInvoiceService
{
    public function __construct(
        private readonly FiscalCompanyResolver $companyResolver,
        private readonly CredentialStore $credentialStore,
        private readonly TokenCacheService $tokenCache,
        private readonly Wsfev1Client $wsfev1,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{document: FiscalDocument, idempotent_replay: bool}
     */
    public function issue(array $payload, ?string $traceId = null): array
    {
        $company = $this->companyResolver->fromPayload($payload);
        $this->credentialStore->activeFor($company);

        $idempotencyKey = (string) $payload['idempotency_key'];
        $existing = $company->documents()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return ['document' => $existing->refresh(), 'idempotent_replay' => true];
        }

        $normalized = $this->normalizePayload($company, $payload);

        try {
            /** @var FiscalDocument $document */
            $document = DB::transaction(function () use ($company, $payload, $normalized, $idempotencyKey): FiscalDocument {
                return $company->documents()->create([
                    'origin_type' => $normalized['origin']['type'],
                    'origin_id' => $normalized['origin']['id'],
                    'document_type' => $payload['document_type'] ?? null,
                    'point_of_sale' => $normalized['point_of_sale'],
                    'voucher_type' => $normalized['voucher_type'],
                    'concept' => $normalized['concept'],
                    'status' => 'processing',
                    'idempotency_key' => $idempotencyKey,
                    'normalized_payload' => $normalized,
                    'metadata' => $payload['metadata'] ?? null,
                ]);
            });
        } catch (QueryException) {
            $existing = $company->documents()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return ['document' => $existing->refresh(), 'idempotent_replay' => true];
            }

            throw new FiscalException('Could not persist fiscal document.', 500, 'document_persist_failed');
        }

        $this->recordEvent($document, 'created', 'Fiscal document accepted for processing.', [
            'trace_id' => $traceId,
        ]);

        return [
            'document' => $this->authorizeDocument($document, false, $traceId),
            'idempotent_replay' => false,
        ];
    }

    public function reconcile(FiscalDocument $document, ?string $traceId = null): FiscalDocument
    {
        $document->loadMissing('company');
        $this->credentialStore->activeFor($document->company);

        if (! $document->document_number) {
            throw new FiscalException('Document has no voucher number to reconcile.', 409, 'document_without_number');
        }

        $ticket = $this->tokenCache->get($document->company);
        $startedAt = microtime(true);
        $attempt = $this->createAttempt($document, 'FECompConsultar', $traceId);

        try {
            $response = $this->wsfev1->consult(
                $document->company,
                $ticket,
                $document->point_of_sale,
                $document->voucher_type,
                $document->document_number,
                $document,
                $traceId,
            );

            $this->finishAttempt($attempt, 'completed', $startedAt, response: $response);

            return $this->applyConsultResponse($document, $response);
        } catch (FiscalException $exception) {
            $this->finishAttempt($attempt, 'failed', $startedAt, errorCode: $exception->errorCode(), errorMessage: $exception->getMessage());

            $document->forceFill([
                'status' => $exception->errorCode() === 'arca_timeout' ? 'uncertain' : 'error',
                'error_code' => $exception->errorCode(),
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
            ])->save();

            $this->recordEvent($document, 'reconcile_failed', $exception->getMessage(), [
                'error_code' => $exception->errorCode(),
            ]);

            return $document->refresh();
        }
    }

    /**
     * @return array{document: FiscalDocument, reconciled_before_retry: bool}
     */
    public function retry(FiscalDocument $document, ?string $traceId = null): array
    {
        if ($document->status === 'authorized') {
            return ['document' => $document->refresh(), 'reconciled_before_retry' => false];
        }

        if ($document->status === 'rejected') {
            throw new FiscalException('Rejected documents cannot be retried blindly.', 409, 'document_rejected');
        }

        $reconciledBeforeRetry = false;

        if ($document->document_number) {
            $document = $this->reconcile($document, $traceId);
            $reconciledBeforeRetry = true;

            if ($document->status === 'authorized') {
                return ['document' => $document, 'reconciled_before_retry' => true];
            }
        }

        return [
            'document' => $this->authorizeDocument($document, true, $traceId),
            'reconciled_before_retry' => $reconciledBeforeRetry,
        ];
    }

    private function authorizeDocument(FiscalDocument $document, bool $reuseNumber, ?string $traceId = null): FiscalDocument
    {
        $document->loadMissing('company');
        $ticket = $this->tokenCache->get($document->company);

        if (! $reuseNumber || ! $document->document_number) {
            $last = $this->wsfev1->lastAuthorized(
                $document->company,
                $ticket,
                $document->point_of_sale,
                $document->voucher_type,
                $document,
                $traceId,
            );

            $lastNumber = (int) data_get($last, 'CbteNro', 0);

            $document->forceFill([
                'document_number' => $lastNumber + 1,
            ])->save();
        }

        $request = $this->buildFeCaeRequest($document);
        $document->forceFill([
            'status' => 'processing',
            'request_payload' => $request,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $attempt = $this->createAttempt($document, 'FECAESolicitar', $traceId, $request);
        $startedAt = microtime(true);

        try {
            $response = $this->wsfev1->authorize($document->company, $ticket, $request, $document, $traceId);
            $this->finishAttempt($attempt, 'completed', $startedAt, response: $response);

            return $this->applyAuthorizationResponse($document, $response);
        } catch (FiscalException $exception) {
            $status = $exception->errorCode() === 'arca_timeout' ? 'uncertain' : 'error';

            $this->finishAttempt($attempt, 'failed', $startedAt, errorCode: $exception->errorCode(), errorMessage: $exception->getMessage());

            $document->forceFill([
                'status' => $status,
                'error_code' => $exception->errorCode(),
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
            ])->save();

            $this->recordEvent($document, $status, $exception->getMessage(), [
                'error_code' => $exception->errorCode(),
            ]);

            return $document->refresh();
        } catch (Throwable $exception) {
            $this->finishAttempt($attempt, 'failed', $startedAt, errorCode: 'unexpected_error', errorMessage: $exception->getMessage());

            $document->forceFill([
                'status' => 'error',
                'error_code' => 'unexpected_error',
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
            ])->save();

            $this->recordEvent($document, 'error', $exception->getMessage());

            return $document->refresh();
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function applyAuthorizationResponse(FiscalDocument $document, array $response): FiscalDocument
    {
        $detail = $this->firstItem(data_get($response, 'FeDetResp.FECAEDetResponse', []));
        $errors = $this->messages(data_get($response, 'Errors.Err', []));
        $events = $this->messages(data_get($response, 'Events.Evt', []));
        $observations = $this->messages(data_get($detail, 'Observaciones.Obs', []));
        $result = (string) (data_get($detail, 'Resultado') ?: data_get($response, 'FeCabResp.Resultado', ''));
        $cae = data_get($detail, 'CAE');
        $caeDueDate = data_get($detail, 'CAEFchVto');

        $status = $result === 'A' && is_string($cae) && $cae !== ''
            ? 'authorized'
            : ($result === 'R' ? 'rejected' : ($errors !== [] ? 'error' : 'uncertain'));

        $document->forceFill([
            'status' => $status,
            'cae' => is_string($cae) && $cae !== '' ? $cae : null,
            'cae_expires_at' => $this->parseAfipDate($caeDueDate),
            'response_payload' => $response,
            'error_code' => data_get($errors, '0.code') ?? data_get($observations, '0.code'),
            'error_message' => data_get($errors, '0.message') ?? data_get($observations, '0.message'),
            'observations' => [
                'observations' => $observations,
                'events' => $events,
                'errors' => $errors,
            ],
            'processed_at' => now(),
        ])->save();

        $this->recordEvent($document, $status, 'WSFEv1 authorization finished.', [
            'result' => $result,
            'cae' => $document->cae,
        ]);

        return $document->refresh();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function applyConsultResponse(FiscalDocument $document, array $response): FiscalDocument
    {
        $result = data_get($response, 'ResultGet', []);
        $errors = $this->messages(data_get($response, 'Errors.Err', []));
        $events = $this->messages(data_get($response, 'Events.Evt', []));
        $cae = data_get($result, 'CodAutorizacion') ?: data_get($result, 'CAE');
        $caeDueDate = data_get($result, 'FchVto') ?: data_get($result, 'CAEFchVto');

        $status = is_string($cae) && $cae !== ''
            ? 'authorized'
            : ($errors !== [] ? 'uncertain' : $document->status);

        $document->forceFill([
            'status' => $status,
            'cae' => is_string($cae) && $cae !== '' ? $cae : $document->cae,
            'cae_expires_at' => $this->parseAfipDate($caeDueDate) ?: $document->cae_expires_at,
            'response_payload' => $response,
            'error_code' => data_get($errors, '0.code'),
            'error_message' => data_get($errors, '0.message'),
            'observations' => [
                'events' => $events,
                'errors' => $errors,
            ],
            'processed_at' => now(),
        ])->save();

        $this->recordEvent($document, 'reconciled', 'Fiscal document reconciled against WSFEv1.', [
            'status' => $status,
        ]);

        return $document->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(FiscalCompany $company, array $payload): array
    {
        $amounts = $payload['amounts'];
        $customer = $payload['customer'] ?? [];
        $pointOfSale = $payload['point_of_sale'] ?? $company->default_point_of_sale;
        $voucherType = $payload['cbte_type'] ?? $company->default_voucher_type;
        $concept = (int) ($payload['concept'] ?? config('fiscal.defaults.concept', 1));

        if (! $pointOfSale) {
            throw new FiscalException('Point of sale is required.', 422, 'point_of_sale_required');
        }

        if (! $voucherType) {
            throw new FiscalException('Voucher type is required.', 422, 'voucher_type_required');
        }

        $docType = $customer['doc_type'] ?? config('fiscal.defaults.consumer_final_doc_type', 99);
        $docNumber = $customer['doc_number'] ?? config('fiscal.defaults.consumer_final_doc_number', 0);

        return [
            'origin' => $this->origin($payload),
            'point_of_sale' => (int) $pointOfSale,
            'voucher_type' => (int) $voucherType,
            'concept' => $concept,
            'customer' => [
                'doc_type' => (int) $docType,
                'doc_number' => (int) $docNumber,
                'name' => $customer['name'] ?? null,
                'tax_condition' => $customer['tax_condition'] ?? null,
                'tax_condition_id' => $this->taxConditionId($customer, (int) $docType),
                'email' => $customer['email'] ?? null,
            ],
            'amounts' => [
                'imp_total' => $this->decimal($amounts['imp_total']),
                'imp_neto' => $this->decimal($amounts['imp_neto']),
                'imp_iva' => $this->decimal($amounts['imp_iva'] ?? 0),
                'imp_trib' => $this->decimal($amounts['imp_trib'] ?? 0),
                'imp_op_ex' => $this->decimal($amounts['imp_op_ex'] ?? 0),
                'imp_tot_conc' => $this->decimal($amounts['imp_tot_conc'] ?? 0),
                'iva_items' => $this->ivaItems($amounts),
                'trib_items' => $this->tribItems($amounts),
            ],
            'currency' => $payload['currency'] ?? config('fiscal.defaults.currency', 'PES'),
            'currency_rate' => $this->decimal($payload['currency_rate'] ?? config('fiscal.defaults.currency_rate', 1), 6),
            'voucher_date' => $this->afipDate($payload['voucher_date'] ?? now()),
            'service_dates' => [
                'from' => isset($payload['service_dates']['from']) ? $this->afipDate($payload['service_dates']['from']) : null,
                'to' => isset($payload['service_dates']['to']) ? $this->afipDate($payload['service_dates']['to']) : null,
                'payment_due_date' => isset($payload['service_dates']['payment_due_date']) ? $this->afipDate($payload['service_dates']['payment_due_date']) : null,
            ],
            'items' => $payload['items'] ?? [],
            'associated_vouchers' => $payload['associated_vouchers'] ?? [],
            'optional_fields' => $payload['optional_fields'] ?? [],
            'activities' => $this->activities($payload['activities'] ?? []),
            'metadata' => $payload['metadata'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFeCaeRequest(FiscalDocument $document): array
    {
        $payload = $document->normalized_payload;
        $amounts = $payload['amounts'];
        $customer = $payload['customer'];

        $detail = [
            'Concepto' => $payload['concept'],
            'DocTipo' => $customer['doc_type'],
            'DocNro' => $customer['doc_number'],
            'CbteDesde' => $document->document_number,
            'CbteHasta' => $document->document_number,
            'CbteFch' => $payload['voucher_date'],
            'ImpTotal' => $amounts['imp_total'],
            'ImpTotConc' => $amounts['imp_tot_conc'],
            'ImpNeto' => $amounts['imp_neto'],
            'ImpOpEx' => $amounts['imp_op_ex'],
            'ImpTrib' => $amounts['imp_trib'],
            'ImpIVA' => $amounts['imp_iva'],
            'MonId' => $payload['currency'],
            'MonCotiz' => $payload['currency_rate'],
            'CondicionIVAReceptorId' => $customer['tax_condition_id'],
        ];

        if ((int) $payload['concept'] !== 1) {
            $detail['FchServDesde'] = $payload['service_dates']['from'];
            $detail['FchServHasta'] = $payload['service_dates']['to'];
            $detail['FchVtoPago'] = $payload['service_dates']['payment_due_date'];
        }

        if ($amounts['iva_items'] !== []) {
            $detail['Iva'] = ['AlicIva' => $amounts['iva_items']];
        }

        if ($amounts['trib_items'] !== []) {
            $detail['Tributos'] = ['Tributo' => $amounts['trib_items']];
        }

        if ($payload['associated_vouchers'] !== []) {
            $detail['CbtesAsoc'] = ['CbteAsoc' => $payload['associated_vouchers']];
        }

        if ($payload['optional_fields'] !== []) {
            $detail['Opcionales'] = ['Opcional' => $payload['optional_fields']];
        }

        if ($payload['activities'] !== []) {
            $detail['Actividades'] = ['Actividad' => $payload['activities']];
        }

        return [
            'FeCabReq' => [
                'CantReg' => 1,
                'PtoVta' => $document->point_of_sale,
                'CbteTipo' => $document->voucher_type,
            ],
            'FeDetReq' => [
                'FECAEDetRequest' => [$detail],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string, id: string|null}
     */
    private function origin(array $payload): array
    {
        if (! empty($payload['sale_id'])) {
            return ['type' => 'sale', 'id' => (string) $payload['sale_id']];
        }

        if (! empty($payload['payment_id'])) {
            return ['type' => 'payment', 'id' => (string) $payload['payment_id']];
        }

        return ['type' => 'manual', 'id' => null];
    }

    /**
     * @param  array<string, mixed>  $amounts
     * @return array<int, array<string, string|int>>
     */
    private function ivaItems(array $amounts): array
    {
        $items = $amounts['iva_items'] ?? [];

        if (is_array($items) && $items !== []) {
            return array_map(fn ($item) => [
                'Id' => (int) $item['id'],
                'BaseImp' => $this->decimal($item['base_imp']),
                'Importe' => $this->decimal($item['importe']),
            ], $items);
        }

        if ((float) ($amounts['imp_iva'] ?? 0) <= 0) {
            return [];
        }

        return [[
            'Id' => (int) config('fiscal.defaults.iva_id', 5),
            'BaseImp' => $this->decimal($amounts['imp_neto'] ?? 0),
            'Importe' => $this->decimal($amounts['imp_iva']),
        ]];
    }

    /**
     * @param  array<string, mixed>  $amounts
     * @return array<int, array<string, string|int>>
     */
    private function tribItems(array $amounts): array
    {
        $items = $amounts['trib_items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return array_map(fn ($item) => [
            'Id' => (int) $item['id'],
            'Desc' => (string) ($item['desc'] ?? ''),
            'BaseImp' => $this->decimal($item['base_imp'] ?? 0),
            'Alic' => $this->decimal($item['alic'] ?? 0),
            'Importe' => $this->decimal($item['importe'] ?? 0),
        ], $items);
    }

    /**
     * @return array<int, array{Id: int}>
     */
    private function activities(mixed $activities): array
    {
        if (! is_array($activities)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $activity): ?array {
            if (is_numeric($activity)) {
                return ['Id' => (int) $activity];
            }

            if (is_array($activity) && isset($activity['id']) && is_numeric($activity['id'])) {
                return ['Id' => (int) $activity['id']];
            }

            if (is_array($activity) && isset($activity['Id']) && is_numeric($activity['Id'])) {
                return ['Id' => (int) $activity['Id']];
            }

            return null;
        }, $activities)));
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

    private function finishAttempt(FiscalDocumentAttempt $attempt, string $status, float $startedAt, ?array $response = null, ?string $errorCode = null, ?string $errorMessage = null): void
    {
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
     * @return array<int, array{code: string|null, message: string|null}>
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

            return [
                'code' => isset($item['Code']) ? (string) $item['Code'] : null,
                'message' => isset($item['Msg']) ? (string) $item['Msg'] : null,
            ];
        }, $items)));
    }

    private function recordEvent(FiscalDocument $document, string $type, ?string $message = null, ?array $data = null): void
    {
        $document->events()->create([
            'type' => $type,
            'message' => $message,
            'data' => $data,
            'created_at' => now(),
        ]);
    }

    private function afipDate(mixed $value): string
    {
        return Carbon::parse($value)->format('Ymd');
    }

    private function parseAfipDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::createFromFormat('Ymd', $value)->startOfDay();
    }

    private function decimal(mixed $value, int $scale = 2): string
    {
        return number_format((float) $value, $scale, '.', '');
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function taxConditionId(array $customer, int $docType): ?int
    {
        if (isset($customer['tax_condition_id'])) {
            return (int) $customer['tax_condition_id'];
        }

        if (isset($customer['tax_condition']) && is_numeric($customer['tax_condition'])) {
            return (int) $customer['tax_condition'];
        }

        if (isset($customer['tax_condition']) && is_string($customer['tax_condition'])) {
            return match (strtolower($customer['tax_condition'])) {
                'iva_responsable_inscripto', 'responsable_inscripto', 'ri' => 1,
                'monotributo', 'monotributista' => 6,
                'iva_exento', 'exento' => 4,
                'consumidor_final', 'cf' => 5,
                default => null,
            };
        }

        if ($docType === (int) config('fiscal.defaults.consumer_final_doc_type', 99)) {
            return (int) config('fiscal.defaults.consumer_final_tax_condition_id', 5);
        }

        return null;
    }
}
