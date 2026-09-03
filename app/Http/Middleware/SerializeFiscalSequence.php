<?php

namespace App\Http\Middleware;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalCompanyResolver;
use App\Services\Fiscal\FiscalVoucherResolver;
use App\Support\FiscalPointOfSale;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class SerializeFiscalSequence
{
    public function __construct(
        private readonly FiscalCompanyResolver $companyResolver,
        private readonly FiscalVoucherResolver $voucherResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        [$company, $pointOfSale, $voucherType, $idempotencyKey, $document] = $this->context($request);
        $operation = $this->operation($request);
        $this->assertNoUnresolvedGap($company, $pointOfSale, $voucherType, $idempotencyKey, $document, $operation);

        $storeName = (string) (config('fiscal-sequence.store') ?: config('cache.default'));
        $store = Cache::store($storeName);
        $driver = (string) config("cache.stores.{$storeName}.driver");

        if (! app()->environment('testing') && ! in_array($driver, ['redis', 'database', 'memcached', 'dynamodb'], true)) {
            throw new FiscalException('La emisión fiscal requiere un lock distribuido configurado.', 503, 'fiscal_sequence_lock_unavailable', [
                'store' => $storeName,
                'driver' => $driver,
            ]);
        }

        $lock = $store->lock(
            $this->lockKey($company, $pointOfSale, $voucherType),
            // Must outlive WSAA + last-number lookup + one authorization.
            max(120, (int) config('fiscal-sequence.ttl_seconds', 240)),
        );

        try {
            return $lock->block(max(0, (int) config('fiscal-sequence.wait_seconds', 15)), function () use ($request, $next, $company, $pointOfSale, $voucherType, $idempotencyKey, $document, $operation): Response {
                $document?->refresh();
                $this->assertNoUnresolvedGap($company, $pointOfSale, $voucherType, $idempotencyKey, $document, $operation);

                return $next($request);
            });
        } catch (LockTimeoutException $exception) {
            throw new FiscalException('La secuencia fiscal está ocupada; reintentá la operación.', 409, 'fiscal_sequence_busy', [
                'fiscal_company_id' => $company->id,
                'environment' => $company->environment,
                'point_of_sale' => $pointOfSale,
                'voucher_type' => $voucherType,
                'retryable' => true,
            ], $exception);
        }
    }

    /** @return array{FiscalCompany,int,int,?string,?FiscalDocument} */
    private function context(Request $request): array
    {
        $routeDocument = $request->route('document');
        if ($routeDocument instanceof FiscalDocument || is_numeric($routeDocument)) {
            $document = $routeDocument instanceof FiscalDocument ? $routeDocument : FiscalDocument::query()->with('company')->findOrFail((int) $routeDocument);
            $document->loadMissing('company');

            return [$document->company, (int) $document->point_of_sale, (int) $document->voucher_type, $document->idempotency_key, $document];
        }

        $payload = $request->all();
        $routeCompany = $request->route('company');

        if (is_scalar($routeCompany) && (string) $routeCompany !== '') {
            $company = $this->companyResolver->resolve((string) $routeCompany);
            $pointOfSale = (int) ($payload['point_of_sale'] ?? 0);
            $voucherType = (int) ($payload['cbte_type'] ?? 0);

            if (! FiscalPointOfSale::isValid($pointOfSale)) {
                throw new FiscalException('Point of sale is required.', 422, 'point_of_sale_required');
            }

            if ($voucherType < 1) {
                throw new FiscalException('Voucher type is required.', 422, 'voucher_type_required');
            }

            return [$company, $pointOfSale, $voucherType, null, null];
        }

        $company = $this->companyResolver->fromPayload($payload);
        $voucher = $this->voucherResolver->resolve($company, $payload);
        $pointOfSale = (int) ($payload['point_of_sale'] ?? $company->default_point_of_sale);
        if (! FiscalPointOfSale::isValid($pointOfSale)) {
            throw new FiscalException('Point of sale is required.', 422, 'point_of_sale_required');
        }

        return [$company, $pointOfSale, (int) $voucher['voucher_type'], isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null, null];
    }

    private function assertNoUnresolvedGap(
        FiscalCompany $company,
        int $pointOfSale,
        int $voucherType,
        ?string $idempotencyKey,
        ?FiscalDocument $target,
        string $operation,
    ): void {
        // Reconciliation is a read/repair operation. It must remain available
        // for every unresolved document in a historical sequence, including its
        // own uncertain target, while the sequence lock still serializes it.
        if ($operation === 'reconcile') {
            return;
        }

        $unresolved = FiscalDocument::query()
            ->where('fiscal_company_id', $company->id)
            ->where('point_of_sale', $pointOfSale)
            ->where('voucher_type', $voucherType)
            ->whereNotNull('document_number')
            ->whereIn('status', ['processing', 'uncertain'])
            // A repeated POST for an existing idempotency key is a retrieval,
            // not a new authorization. Exclude only that exact operation, not
            // any other unresolved document in the sequence.
            ->when($operation === 'store' && $idempotencyKey !== null, fn ($query) => $query->where('idempotency_key', '!=', $idempotencyKey))
            // Retry reconciles its own target before deciding whether a new
            // FECAESolicitar is safe. A different gap must still block it.
            ->when($operation === 'retry' && $target !== null, fn ($query) => $query->whereKeyNot($target->id))
            ->latest('id')
            ->first();

        if ($unresolved === null) {
            return;
        }

        throw new FiscalException('Existe un comprobante de esta secuencia sin resultado confirmado; conciliá antes de emitir otro.', 409, 'fiscal_sequence_requires_reconcile', [
            'document_id' => $unresolved->id,
            'document_number' => $unresolved->document_number,
            'requires_reconcile' => true,
        ]);
    }

    private function operation(Request $request): string
    {
        $method = $request->route()?->getActionMethod();

        return match ($method) {
            'retry' => 'retry',
            'reconcile', 'reconcileSequence' => 'reconcile',
            default => 'store',
        };
    }

    private function lockKey(FiscalCompany $company, int $pointOfSale, int $voucherType): string
    {
        return "fiscal:sequence:{$company->id}:{$company->environment}:{$pointOfSale}:{$voucherType}";
    }
}
