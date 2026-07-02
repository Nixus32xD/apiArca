<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\FiscalIvaBookRequest;
use App\Http\Requests\Fiscal\StoreFiscalPurchaseRequest;
use App\Http\Resources\FiscalPurchaseResource;
use App\Models\FiscalPurchase;
use App\Services\Fiscal\FiscalAmountValidator;
use App\Services\Fiscal\FiscalCompanyResolver;
use App\Services\Fiscal\FiscalIvaBookService;
use App\Services\Fiscal\FiscalVoucherResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Throwable;

class FiscalPurchaseController extends Controller
{
    public function __construct(
        private readonly FiscalCompanyResolver $companyResolver,
        private readonly FiscalAmountValidator $amountValidator,
        private readonly FiscalIvaBookService $ivaBookService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        try {
            $data = $request->validate([
                'business_id' => ['required_without:external_business_id', 'string', 'max:120'],
                'external_business_id' => ['required_without:business_id', 'string', 'max:120'],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
                'supplier_cuit' => ['nullable', 'digits:11'],
            ]);

            $company = $this->companyResolver->fromPayload($data);
            $query = $company->purchases()->with(['company', 'ivaItems'])->latest('voucher_date');

            if (! empty($data['date_from'])) {
                $query->whereDate('voucher_date', '>=', $data['date_from']);
            }

            if (! empty($data['date_to'])) {
                $query->whereDate('voucher_date', '<=', $data['date_to']);
            }

            if (! empty($data['supplier_cuit'])) {
                $query->where('supplier_cuit', $data['supplier_cuit']);
            }

            return FiscalPurchaseResource::collection($query->limit(100)->get());
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    public function store(StoreFiscalPurchaseRequest $request): JsonResponse
    {
        try {
            $purchase = $this->persist(new FiscalPurchase, $request->validated());

            return (new FiscalPurchaseResource($purchase->load(['company', 'ivaItems'])))
                ->response()
                ->setStatusCode(201);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function show(FiscalPurchase $purchase): FiscalPurchaseResource
    {
        return new FiscalPurchaseResource($purchase->load(['company', 'ivaItems']));
    }

    public function update(StoreFiscalPurchaseRequest $request, FiscalPurchase $purchase): FiscalPurchaseResource|JsonResponse
    {
        try {
            return new FiscalPurchaseResource($this->persist($purchase, $request->validated())->load(['company', 'ivaItems']));
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function destroy(FiscalPurchase $purchase): JsonResponse
    {
        $purchase->delete();

        return response()->json(null, 204);
    }

    public function ivaBook(FiscalIvaBookRequest $request): JsonResponse
    {
        try {
            $company = $this->companyResolver->fromPayload($request->validated());

            return response()->json([
                'data' => $this->ivaBookService->purchases(
                    $company,
                    $request->validated('date_from'),
                    $request->validated('date_to'),
                ),
            ]);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(FiscalPurchase $purchase, array $data): FiscalPurchase
    {
        $company = $this->companyResolver->fromPayload($data);
        $voucherType = (int) $data['cbte_type'];
        $amounts = $this->normalizeAmounts($data['amounts'], $voucherType);
        $associatedVouchers = $data['associated_vouchers'] ?? [];

        if (FiscalVoucherResolver::requiresAssociatedVoucher($voucherType) && $associatedVouchers === []) {
            throw new FiscalException('Credit and debit notes require at least one associated voucher.', 422, 'associated_voucher_required');
        }

        if (FiscalVoucherResolver::voucherClass($voucherType) === null) {
            throw new FiscalException('Unsupported fiscal voucher type.', 422, 'unsupported_voucher_type', [
                'voucher_type' => $voucherType,
            ]);
        }

        $this->amountValidator->validatePurchase($voucherType, $amounts);

        return DB::transaction(function () use ($purchase, $company, $data, $voucherType, $amounts, $associatedVouchers): FiscalPurchase {
            $purchase->forceFill([
                'fiscal_company_id' => $company->id,
                'origin_type' => data_get($data, 'origin.type') ?? ($data['origin_type'] ?? 'manual'),
                'origin_id' => data_get($data, 'origin.id') ?? ($data['origin_id'] ?? null),
                'voucher_date' => $data['voucher_date'],
                'accounting_date' => $data['accounting_date'] ?? null,
                'voucher_type' => $voucherType,
                'document_type' => FiscalVoucherResolver::documentTypeForVoucher($voucherType),
                'point_of_sale' => $data['point_of_sale'],
                'document_number' => $data['document_number'],
                'supplier_cuit' => data_get($data, 'supplier.cuit'),
                'supplier_name' => data_get($data, 'supplier.name'),
                'supplier_iva_condition' => data_get($data, 'supplier.iva_condition'),
                'imp_total' => $amounts['imp_total'],
                'imp_neto' => $amounts['imp_neto'],
                'imp_iva' => $amounts['imp_iva'],
                'imp_trib' => $amounts['imp_trib'],
                'imp_op_ex' => $amounts['imp_op_ex'],
                'imp_tot_conc' => $amounts['imp_tot_conc'],
                'currency' => $data['currency'] ?? config('fiscal.defaults.currency', 'PES'),
                'currency_rate' => $this->decimal($data['currency_rate'] ?? config('fiscal.defaults.currency_rate', 1), 6),
                'payment_method' => $this->paymentMethod($data['payment_method'] ?? null),
                'payment_reference' => $data['payment_reference'] ?? null,
                'associated_vouchers' => $associatedVouchers,
                'metadata' => $data['metadata'] ?? null,
            ])->save();

            $purchase->ivaItems()->delete();

            foreach ($amounts['iva_items'] as $item) {
                $ivaId = (int) $item['Id'];

                $purchase->ivaItems()->create([
                    'iva_id' => $ivaId,
                    'rate' => $this->amountValidator->ivaRateFor($ivaId),
                    'base_imp' => $item['BaseImp'],
                    'importe' => $item['Importe'],
                ]);
            }

            return $purchase->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $amounts
     * @return array<string, mixed>
     */
    private function normalizeAmounts(array $amounts, int $voucherType): array
    {
        $ivaItems = $this->ivaItems($amounts);
        $tribItems = $this->tribItems($amounts);

        $normalized = [
            'imp_total' => $this->decimal($amounts['imp_total']),
            'imp_neto' => $this->decimal($amounts['imp_neto']),
            'imp_iva' => array_key_exists('imp_iva', $amounts) ? $this->decimal($amounts['imp_iva']) : $this->sumItems($ivaItems, 'Importe'),
            'imp_trib' => array_key_exists('imp_trib', $amounts) ? $this->decimal($amounts['imp_trib']) : $this->sumItems($tribItems, 'Importe'),
            'imp_op_ex' => $this->decimal($amounts['imp_op_ex'] ?? 0),
            'imp_tot_conc' => $this->decimal($amounts['imp_tot_conc'] ?? 0),
            'iva_items' => $ivaItems,
            'trib_items' => $tribItems,
        ];

        if (FiscalVoucherResolver::isClassC($voucherType)) {
            $normalized['imp_neto'] = $this->decimal(max(0, (float) $normalized['imp_total'] - (float) $normalized['imp_trib']));
            $normalized['imp_iva'] = '0.00';
            $normalized['imp_op_ex'] = '0.00';
            $normalized['imp_tot_conc'] = '0.00';
            $normalized['iva_items'] = [];
        }

        return $normalized;
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
     * @param  array<int, array<string, mixed>>  $items
     */
    private function sumItems(array $items, string $key): string
    {
        return $this->decimal(array_reduce(
            $items,
            fn (float $carry, array $item): float => $carry + (float) ($item[$key] ?? 0),
            0.0,
        ));
    }

    private function paymentMethod(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'cash', 'efectivo' => 'cash',
            'transfer', 'transferencia', 'bank_transfer' => 'bank_transfer',
            'debit_card', 'debito' => 'debit_card',
            'credit_card', 'credito' => 'credit_card',
            'other', 'otro' => 'other',
            default => null,
        };
    }

    private function decimal(mixed $value, int $scale = 2): string
    {
        return number_format((float) $value, $scale, '.', '');
    }

    private function fiscalError(FiscalException $exception): JsonResponse
    {
        return response()->json($exception->toPayload(), $exception->status());
    }

    private function unexpectedError(Throwable $exception): JsonResponse
    {
        report($exception);

        return response()->json([
            'message' => 'Unexpected fiscal purchase API error.',
            'error_code' => 'unexpected_error',
        ], 500);
    }
}
