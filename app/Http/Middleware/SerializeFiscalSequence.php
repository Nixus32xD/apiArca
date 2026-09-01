<?php

namespace App\Http\Middleware;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalCompanyResolver;
use App\Services\Fiscal\FiscalVoucherResolver;
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
        $this->assertNoUnresolvedGap($company, $pointOfSale, $voucherType, $idempotencyKey, $document);

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
            return $lock->block(max(0, (int) config('fiscal-sequence.wait_seconds', 15)), function () use ($request, $next, $company, $pointOfSale, $voucherType, $idempotencyKey, $document): Response {
                $document?->refresh();
                $this->assertNoUnresolvedGap($company, $pointOfSale, $voucherType, $idempotencyKey, $document);

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
        $company = $this->companyResolver->fromPayload($payload);
        $voucher = $this->voucherResolver->resolve($company, $payload);
        $pointOfSale = (int) ($payload['point_of_sale'] ?? $company->default_point_of_sale);
        if ($pointOfSale <= 0) {
            throw new FiscalException('Point of sale is required.', 422, 'point_of_sale_required');
        }

        return [$company, $pointOfSale, (int) $voucher['voucher_type'], isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null, null];
    }

    private function assertNoUnresolvedGap(FiscalCompany $company, int $pointOfSale, int $voucherType, ?string $idempotencyKey, ?FiscalDocument $target): void
    {
        $unresolved = FiscalDocument::query()
            ->where('fiscal_company_id', $company->id)
            ->where('point_of_sale', $pointOfSale)
            ->where('voucher_type', $voucherType)
            ->whereNotNull('document_number')
            ->whereIn('status', ['processing', 'uncertain'])
            ->when($target, fn ($query) => $query->whereKey($target->id))
            ->latest('id')
            ->first();

        if ($unresolved === null || ($idempotencyKey !== null && hash_equals($unresolved->idempotency_key, $idempotencyKey))) {
            return;
        }

        throw new FiscalException('Existe un comprobante de esta secuencia sin resultado confirmado; conciliá antes de emitir otro.', 409, 'fiscal_sequence_requires_reconcile', [
            'document_id' => $unresolved->id,
            'document_number' => $unresolved->document_number,
            'requires_reconcile' => true,
        ]);
    }

    private function lockKey(FiscalCompany $company, int $pointOfSale, int $voucherType): string
    {
        return "fiscal:sequence:{$company->id}:{$company->environment}:{$pointOfSale}:{$voucherType}";
    }
}
