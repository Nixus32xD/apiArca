<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\ByOriginFiscalDocumentRequest;
use App\Http\Requests\Fiscal\FiscalIvaBookRequest;
use App\Http\Requests\Fiscal\StoreFiscalDocumentRequest;
use App\Http\Resources\FiscalDocumentResource;
use App\Jobs\SendFiscalDocumentEmailJob;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalCompanyResolver;
use App\Services\Fiscal\FiscalDocumentPdfService;
use App\Services\Fiscal\FiscalDocumentQrService;
use App\Services\Fiscal\FiscalInvoiceService;
use App\Services\Fiscal\FiscalIvaBookService;
use App\Services\Fiscal\FiscalRecordScopeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FiscalDocumentController extends Controller
{
    public function __construct(
        private readonly FiscalInvoiceService $invoiceService,
        private readonly FiscalCompanyResolver $companyResolver,
        private readonly FiscalIvaBookService $ivaBookService,
        private readonly FiscalDocumentQrService $qrService,
        private readonly FiscalDocumentPdfService $pdfService,
        private readonly FiscalRecordScopeGuard $scopeGuard,
    ) {}

    public function store(StoreFiscalDocumentRequest $request): JsonResponse
    {
        try {
            $result = $this->invoiceService->issue($request->validated(), $this->traceId($request));
            $resource = new FiscalDocumentResource($result['document']->load(['company', 'ivaItems', 'attempts', 'events']));

            return $resource
                ->additional(['meta' => ['idempotent_replay' => $result['idempotent_replay']]])
                ->response()
                ->setStatusCode($result['idempotent_replay'] ? 200 : 201);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function show(Request $request, FiscalDocument $document): FiscalDocumentResource|JsonResponse
    {
        try {
            $document->loadMissing('company');
            $this->scopeGuard->ensureCompanyMatches($request, $document->company);

            return new FiscalDocumentResource($document->load(['company', 'ivaItems', 'attempts', 'events']));
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    public function byOrigin(ByOriginFiscalDocumentRequest $request): AnonymousResourceCollection|JsonResponse
    {
        try {
            $company = $this->companyResolver->fromPayload($request->validated());
            $query = $company->documents()
                ->with(['company', 'ivaItems'])
                ->where('origin_type', $request->validated('origin_type'))
                ->latest();

            if ($request->filled('origin_id')) {
                $query->where('origin_id', $request->validated('origin_id'));
            }

            return FiscalDocumentResource::collection($query->limit(50)->get());
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    public function ivaSales(FiscalIvaBookRequest $request): JsonResponse
    {
        try {
            $company = $this->companyResolver->fromPayload($request->validated());

            return response()->json([
                'data' => $this->ivaBookService->sales(
                    $company,
                    $request->validated('date_from'),
                    $request->validated('date_to'),
                ),
            ]);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    public function ivaPosition(FiscalIvaBookRequest $request): JsonResponse
    {
        try {
            $company = $this->companyResolver->fromPayload($request->validated());
            $sales = $this->ivaBookService->sales(
                $company,
                $request->validated('date_from'),
                $request->validated('date_to'),
            );
            $purchases = $this->ivaBookService->purchases(
                $company,
                $request->validated('date_from'),
                $request->validated('date_to'),
            );
            $debitVat = (float) data_get($sales, 'totals.imp_iva', 0);
            $creditVat = (float) data_get($purchases, 'totals.imp_iva', 0);
            $estimatedPosition = $debitVat - $creditVat;

            return response()->json([
                'data' => [
                    'company' => $sales['company'],
                    'period' => $sales['period'],
                    'sales_totals' => $sales['totals'],
                    'purchase_totals' => $purchases['totals'],
                    'debit_vat' => $this->decimal($debitVat),
                    'credit_vat' => $this->decimal($creditVat),
                    'estimated_position' => $this->decimal($estimatedPosition),
                    'result' => $this->ivaPositionResult($estimatedPosition),
                    'iva_by_aliquot' => $this->ivaPositionByAliquot(
                        (array) data_get($sales, 'totals.iva_by_aliquot', []),
                        (array) data_get($purchases, 'totals.iva_by_aliquot', []),
                    ),
                ],
            ]);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    public function pdf(Request $request, FiscalDocument $document): Response|JsonResponse
    {
        try {
            $document->loadMissing('company');
            $this->scopeGuard->ensureCompanyMatches($request, $document->company);
            $document = $this->pdfService->store($document);
            $pdf = (string) Storage::disk($this->pdfService->disk())->get($document->pdf_storage_key);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$this->pdfService->filename($document).'"',
                'X-Fiscal-PDF-SHA256' => (string) $document->pdf_sha256,
            ]);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function qr(Request $request, FiscalDocument $document): JsonResponse
    {
        try {
            $document->loadMissing('company');
            $this->scopeGuard->ensureCompanyMatches($request, $document->company);

            return response()->json([
                'data' => [
                    'url' => $this->qrService->url($document),
                    'payload' => $this->qrService->payload($document),
                    'svg' => $this->qrService->svg($document),
                ],
            ]);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    public function email(Request $request, FiscalDocument $document): JsonResponse
    {
        try {
            $document->loadMissing('company');
            $this->scopeGuard->ensureCompanyMatches($request, $document->company);

            if ($document->status !== 'authorized') {
                throw new FiscalException('Only authorized fiscal documents can be emailed.', 409, 'document_not_authorized');
            }

            $data = $request->validate([
                'email' => ['nullable', 'email', 'max:255'],
                'business_id' => ['nullable', 'string', 'max:120'],
                'external_business_id' => ['nullable', 'string', 'max:120'],
            ]);
            $recipient = $data['email'] ?? data_get($document->normalized_payload, 'customer.email');

            if (! is_string($recipient) || $recipient === '') {
                throw new FiscalException('Customer email is required to send the fiscal document.', 422, 'customer_email_required');
            }

            $document->forceFill([
                'email_to' => $recipient,
                'email_status' => 'pending',
                'email_last_error' => null,
            ])->save();

            SendFiscalDocumentEmailJob::dispatch($document->id);

            return (new FiscalDocumentResource($document->refresh()->load(['company', 'ivaItems', 'attempts', 'events'])))
                ->response()
                ->setStatusCode(202);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function retry(Request $request, FiscalDocument $document): JsonResponse
    {
        try {
            $document->loadMissing('company');
            $this->scopeGuard->ensureCompanyMatches($request, $document->company);
            $result = $this->invoiceService->retry($document, $this->traceId($request));
            $resource = new FiscalDocumentResource($result['document']->load(['company', 'ivaItems', 'attempts', 'events']));

            return $resource
                ->additional(['meta' => ['reconciled_before_retry' => $result['reconciled_before_retry']]])
                ->response();
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function reconcile(Request $request, FiscalDocument $document): JsonResponse
    {
        try {
            $document->loadMissing('company');
            $this->scopeGuard->ensureCompanyMatches($request, $document->company);
            $resource = new FiscalDocumentResource(
                $this->invoiceService->reconcile($document, $this->traceId($request))->load(['company', 'ivaItems', 'attempts', 'events'])
            );

            return $resource->response();
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    private function fiscalError(FiscalException $exception): JsonResponse
    {
        return response()->json($exception->toPayload(), $exception->status());
    }

    private function unexpectedError(Throwable $exception): JsonResponse
    {
        report($exception);

        return response()->json([
            'message' => 'Unexpected fiscal API error.',
            'error_code' => 'unexpected_error',
        ], 500);
    }

    private function traceId(Request $request): ?string
    {
        return $request->header('X-Trace-Id') ?: $request->header('X-Request-Id');
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function ivaPositionResult(float $estimatedPosition): string
    {
        if (abs($estimatedPosition) < 0.005) {
            return 'zero';
        }

        return $estimatedPosition > 0 ? 'payable' : 'credit';
    }

    /**
     * @param  array<int, array<string, mixed>>  $sales
     * @param  array<int, array<string, mixed>>  $purchases
     * @return array<int, array<string, mixed>>
     */
    private function ivaPositionByAliquot(array $sales, array $purchases): array
    {
        $items = [];

        foreach ($sales as $item) {
            $id = (int) ($item['id'] ?? 0);
            $items[$id] ??= [
                'id' => $id,
                'rate' => $item['rate'] ?? null,
                'sales_base_imp' => 0.0,
                'sales_iva' => 0.0,
                'purchase_base_imp' => 0.0,
                'purchase_iva' => 0.0,
            ];
            $items[$id]['sales_base_imp'] += (float) ($item['base_imp'] ?? 0);
            $items[$id]['sales_iva'] += (float) ($item['importe'] ?? 0);
        }

        foreach ($purchases as $item) {
            $id = (int) ($item['id'] ?? 0);
            $items[$id] ??= [
                'id' => $id,
                'rate' => $item['rate'] ?? null,
                'sales_base_imp' => 0.0,
                'sales_iva' => 0.0,
                'purchase_base_imp' => 0.0,
                'purchase_iva' => 0.0,
            ];
            $items[$id]['rate'] ??= $item['rate'] ?? null;
            $items[$id]['purchase_base_imp'] += (float) ($item['base_imp'] ?? 0);
            $items[$id]['purchase_iva'] += (float) ($item['importe'] ?? 0);
        }

        ksort($items);

        return array_values(array_map(fn (array $item): array => [
            'id' => $item['id'],
            'rate' => $item['rate'],
            'sales_base_imp' => $this->decimal($item['sales_base_imp']),
            'sales_iva' => $this->decimal($item['sales_iva']),
            'purchase_base_imp' => $this->decimal($item['purchase_base_imp']),
            'purchase_iva' => $this->decimal($item['purchase_iva']),
            'estimated_position' => $this->decimal($item['sales_iva'] - $item['purchase_iva']),
        ], $items));
    }
}
