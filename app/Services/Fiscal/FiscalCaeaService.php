<?php

namespace App\Services\Fiscal;

use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\Wsfev1Client;

class FiscalCaeaService
{
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly TokenCacheService $tokenCache,
        private readonly Wsfev1Client $wsfev1,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function request(FiscalCompany $company, string $period, int $order, ?string $traceId = null): array
    {
        $this->credentialStore->activeFor($company);
        $ticket = $this->tokenCache->get($company);

        return $this->wsfev1->requestCaea($company, $ticket, $period, $order, null, $traceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function consult(FiscalCompany $company, string $period, int $order, ?string $traceId = null): array
    {
        $this->credentialStore->activeFor($company);
        $ticket = $this->tokenCache->get($company);

        return $this->wsfev1->consultCaea($company, $ticket, $period, $order, null, $traceId);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function report(FiscalDocument $document, array $request, ?string $traceId = null): array
    {
        $document->loadMissing('company');
        $this->credentialStore->activeFor($document->company);
        $ticket = $this->tokenCache->get($document->company);

        $response = $this->wsfev1->reportCaea($document->company, $ticket, $request, $document, $traceId);
        $this->persistReportResult($document, $request, $response);

        return $response;
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
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    private function persistReportResult(FiscalDocument $document, array $request, array $response): void
    {
        $result = (string) (data_get($response, 'Resultado') ?: data_get($response, 'FeCabResp.Resultado', ''));
        $status = match ($result) {
            'A' => 'authorized',
            'R' => 'rejected',
            default => 'uncertain',
        };

        $document->forceFill([
            'status' => $status,
            'fiscal_status' => $result === 'A' ? 'reported' : $this->fiscalStatus($status),
            'authorization_type' => 'CAEA',
            'authorization_code' => $this->caeaFromReportRequest($request) ?: $document->authorization_code,
            'request_payload' => $request,
            'response_payload' => $response,
            'raw_request' => $request,
            'raw_response' => $response,
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
    }

    private function caeaFromReportRequest(array $request): ?string
    {
        $caea = data_get($request, 'CAEA')
            ?: data_get($request, 'FeDetReq.FECAEADetRequest.0.CAEA')
            ?: data_get($request, 'FeDetReq.FECAEADetRequest.CAEA');

        return is_scalar($caea) && $caea !== '' ? (string) $caea : null;
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
}
