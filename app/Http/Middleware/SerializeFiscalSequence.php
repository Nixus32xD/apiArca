<?php

namespace App\Http\Middleware;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalCompanyResolver;
use App\Services\Fiscal\FiscalRecordScopeGuard;
use App\Services\Fiscal\FiscalVoucherResolver;
use Closure;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class SerializeFiscalSequence
{
    public function __construct(
        private readonly FiscalCompanyResolver $companyResolver,
        private readonly FiscalVoucherResolver $voucherResolver,
        private readonly FiscalRecordScopeGuard $scopeGuard,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        [$company, $pointOfSale, $voucherType, $idempotencyKey, $document] = $this->sequenceContext($request);

        if ($document !== null) {
            $this->scopeGuard->ensureCompanyMatches($request, $company);
            $this->assertRetryNumberWasNotReassigned($document);
        } else {
            $this->assertNoUncertainSequenceGap(
                $company,
                $pointOfSale,
                $voucherType,
                $idempotencyKey,
            );
        }

        $store = (string) (config('fiscal-sequence.store') ?: config('cache.default'));
        $ttlSeconds = max(60, (int) config('fiscal-sequence.ttl_seconds', 240));
        $waitSeconds = max(0, (int) config('fiscal-sequence.wait_seconds', 15));
        $lock = Cache::store($store)->lock(
            $this->lockKey($company, $pointOfSale, $voucherType),
            $ttlSeconds,
        );

        try {
            return $lock->block($waitSeconds, function () use (
                $request,
                $next,
                $company,
                $pointOfSale,
                $voucherType,
                $idempotencyKey,
                $document,
            ): Response {
                // Re-check after obtaining the distributed lock. This closes the
                // TOCTOU window between the pre-check and the critical section.
                if ($document !== null) {
                    $document->refresh();
                    $this->assertRetryNumberWasNotReassigned($document);
                } else {
                    $this->assertNoUncertainSequenceGap(
                        $company,
                        $pointOfSale,
                        $voucherType,
                        $idempotencyKey,
                    );
                }

                return $next($request);
            });
        } catch (LockTimeoutException $exception) {
            throw new FiscalException(
                'La secuencia fiscal esta siendo utilizada por otra emision. Reintenta la operacion.',
                409,
                'fiscal_sequence_busy',
                [
                    'fiscal_company_id' => $company->id,
                    'environment' => $company->environment,
                    'point_of_sale' => $pointOfSale,
                    'voucher_type' => $voucherType,
                    'retryable' => true,
                ],
                $exception,
            );
        }
    }

    /**
     * @return array{0:FiscalCompany,1:int,2:int,3:?string,4:?FiscalDocument}
     */
    private function sequenceContext(Request $request): array
    {
        $routeDocument = $request->route('document');

        if ($routeDocument instanceof FiscalDocument || is_numeric($routeDocument)) {
            $document = $routeDocument instanceof FiscalDocument
                ? $routeDocument
                : FiscalDocument::query()->with('company')->findOrFail((int) $routeDocument);
            $document->loadMissing('company');

            return [
                $document->company,
                (int) $document->point_of_sale,
                (int) $document->voucher_type,
                $document->idempotency_key,
                $document,
            ];
        }

        $payload = $request->all();
        $company = $this->companyResolver->fromPayload($payload);
        $resolved = $this->voucherResolver->resolve($company, $payload);
        $pointOfSale = (int) ($payload['point_of_sale'] ?? $company->default_point_of_sale);
        $voucherType = (int) $resolved['voucher_type'];

        if ($pointOfSale <= 0) {
            throw new FiscalException('Point of sale is required.', 422, 'point_of_sale_required');
        }

        return [
            $company,
            $pointOfSale,
            $voucherType,
            isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null,
            null,
        ];
    }

    private function assertNoUncertainSequenceGap(
        FiscalCompany $company,
        int $pointOfSale,
        int $voucherType,
        ?string $idempotencyKey,
    ): void {
        $unresolved = FiscalDocument::query()
            ->where('fiscal_company_id', $company->id)
            ->where('point_of_sale', $pointOfSale)
            ->where('voucher_type', $voucherType)
            ->whereNotNull('document_number')
            ->whereIn('status', ['processing', 'uncertain'])
            ->latest('id')
            ->first();

        if ($unresolved === null || ($idempotencyKey !== null && hash_equals($unresolved->idempotency_key, $idempotencyKey))) {
            return;
        }

        throw new FiscalException(
            'Existe un comprobante con numeracion fiscal de esta secuencia cuyo resultado aun no esta confirmado. Debe conciliarse antes de emitir el siguiente.',
            409,
            'fiscal_sequence_requires_reconcile',
            [
                'fiscal_company_id' => $company->id,
                'point_of_sale' => $pointOfSale,
                'voucher_type' => $voucherType,
                'document_id' => $unresolved->id,
                'document_number' => $unresolved->document_number,
                'retryable' => false,
                'requires_reconcile' => true,
            ],
        );
    }

    private function assertRetryNumberWasNotReassigned(FiscalDocument $document): void
    {
        if (! $document->document_number || $document->status === 'authorized') {
            return;
        }

        $otherAuthorized = FiscalDocument::query()
            ->where('fiscal_company_id', $document->fiscal_company_id)
            ->where('point_of_sale', $document->point_of_sale)
            ->where('voucher_type', $document->voucher_type)
            ->where('document_number', $document->document_number)
            ->where('status', 'authorized')
            ->where('id', '!=', $document->id)
            ->exists();

        if (! $otherAuthorized) {
            return;
        }

        throw new FiscalException(
            'El numero fiscal de este intento ya fue utilizado por otro comprobante autorizado. No es seguro reintentar este registro.',
            409,
            'fiscal_number_reassigned',
            [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
                'point_of_sale' => $document->point_of_sale,
                'voucher_type' => $document->voucher_type,
                'retryable' => false,
            ],
        );
    }

    private function lockKey(FiscalCompany $company, int $pointOfSale, int $voucherType): string
    {
        return implode(':', [
            'fiscal',
            'issue',
            $company->id,
            $company->environment,
            $pointOfSale,
            $voucherType,
        ]);
    }
}
