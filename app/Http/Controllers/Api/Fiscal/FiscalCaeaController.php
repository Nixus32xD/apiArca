<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\CaeaWithoutMovementRequest;
use App\Http\Requests\Fiscal\RequestFiscalCaeaRequest;
use App\Http\Resources\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalCaeaService;
use App\Services\Fiscal\FiscalCompanyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class FiscalCaeaController extends Controller
{
    public function __construct(
        private readonly FiscalCompanyResolver $companyResolver,
        private readonly FiscalCaeaService $caeaService,
    ) {}

    public function request(RequestFiscalCaeaRequest $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $data = $request->validated();
            $response = $this->caeaService->request($fiscalCompany, $data['period'], (int) $data['order'], $this->traceId($request));

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'caea' => $this->normalizeCaea($response),
                    'raw_response' => $response,
                ],
            ], 201);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function consult(RequestFiscalCaeaRequest $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $data = $request->validated();
            $response = $this->caeaService->consult($fiscalCompany, $data['period'], (int) $data['order'], $this->traceId($request));

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'caea' => $this->normalizeCaea($response),
                    'raw_response' => $response,
                ],
            ]);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function report(Request $request, FiscalDocument $document): JsonResponse
    {
        try {
            $result = $this->caeaService->reportDocument($document, $this->traceId($request));
            $resource = new FiscalDocumentResource($result['document']->load(['company', 'attempts', 'events']));

            return $resource
                ->additional(['meta' => ['raw_response' => $result['response']]])
                ->response();
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function informWithoutMovement(CaeaWithoutMovementRequest $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $data = $request->validated();
            $response = $this->caeaService->informWithoutMovement(
                $fiscalCompany,
                $data['caea'],
                (int) $data['point_of_sale'],
                (int) $data['cbte_type'],
                $this->traceId($request),
            );

            return response()->json(['data' => ['result' => $response]]);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    public function consultWithoutMovement(CaeaWithoutMovementRequest $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $data = $request->validated();
            $response = $this->caeaService->consultWithoutMovement(
                $fiscalCompany,
                $data['caea'],
                (int) $data['point_of_sale'],
                (int) $data['cbte_type'],
                $this->traceId($request),
            );

            return response()->json(['data' => ['result' => $response]]);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function normalizeCaea(array $response): array
    {
        $result = data_get($response, 'ResultGet', $response);

        return [
            'code' => $this->stringOrNull(data_get($result, 'CAEA')),
            'period' => $this->stringOrNull(data_get($result, 'Periodo')),
            'order' => is_numeric(data_get($result, 'Orden')) ? (int) data_get($result, 'Orden') : null,
            'from' => is_numeric(data_get($result, 'FchDesde')) ? (int) data_get($result, 'FchDesde') : null,
            'to' => is_numeric(data_get($result, 'FchHasta')) ? (int) data_get($result, 'FchHasta') : null,
            'due_date' => $this->stringOrNull(data_get($result, 'FchVigHasta') ?? data_get($result, 'FchVto')),
            'report_deadline' => $this->stringOrNull(data_get($result, 'FchTopeInf')),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    private function fiscalError(FiscalException $exception): JsonResponse
    {
        return response()->json($exception->toPayload(), $exception->status());
    }

    private function unexpectedError(Throwable $exception): JsonResponse
    {
        report($exception);

        return response()->json([
            'message' => 'Unexpected fiscal CAEA API error.',
            'error_code' => 'unexpected_error',
        ], 500);
    }

    private function traceId(Request $request): ?string
    {
        return $request->header('X-Trace-Id') ?: $request->header('X-Request-Id');
    }
}
