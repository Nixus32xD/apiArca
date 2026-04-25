<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\ByOriginFiscalDocumentRequest;
use App\Http\Requests\Fiscal\StoreFiscalDocumentRequest;
use App\Http\Resources\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalCompanyResolver;
use App\Services\Fiscal\FiscalInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class FiscalDocumentController extends Controller
{
    public function __construct(
        private readonly FiscalInvoiceService $invoiceService,
        private readonly FiscalCompanyResolver $companyResolver,
    ) {}

    public function store(StoreFiscalDocumentRequest $request): JsonResponse
    {
        try {
            $result = $this->invoiceService->issue($request->validated(), $this->traceId($request));
            $resource = new FiscalDocumentResource($result['document']->load(['company', 'attempts', 'events']));

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

    public function show(FiscalDocument $document): FiscalDocumentResource
    {
        return new FiscalDocumentResource($document->load(['company', 'attempts', 'events']));
    }

    public function byOrigin(ByOriginFiscalDocumentRequest $request): AnonymousResourceCollection|JsonResponse
    {
        try {
            $company = $this->companyResolver->fromPayload($request->validated());
            $query = $company->documents()
                ->with('company')
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

    public function retry(Request $request, FiscalDocument $document): JsonResponse
    {
        try {
            $result = $this->invoiceService->retry($document, $this->traceId($request));
            $resource = new FiscalDocumentResource($result['document']->load(['company', 'attempts', 'events']));

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
            $resource = new FiscalDocumentResource(
                $this->invoiceService->reconcile($document, $this->traceId($request))->load(['company', 'attempts', 'events'])
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
}
