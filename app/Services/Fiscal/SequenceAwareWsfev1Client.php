<?php

namespace App\Services\Fiscal;

use App\Models\AccessTicket;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\Wsfev1Client;

class SequenceAwareWsfev1Client implements Wsfev1Client
{
    public function __construct(
        private readonly WSFEv1Service $inner,
    ) {}

    public function authorize(FiscalCompany $company, AccessTicket $ticket, array $feCaeRequest, ?FiscalDocument $document = null, ?string $traceId = null): array
    {
        return $this->inner->authorize($company, $ticket, $feCaeRequest, $document, $traceId);
    }

    public function lastAuthorized(FiscalCompany $company, AccessTicket $ticket, int $pointOfSale, int $voucherType, ?FiscalDocument $document = null, ?string $traceId = null): array
    {
        $response = $this->inner->lastAuthorized($company, $ticket, $pointOfSale, $voucherType, $document, $traceId);

        // CAEA can assign vouchers while ARCA is unavailable and report them
        // later. In that mode FECompUltimoAutorizado may lag behind the locally
        // reserved sequence, so use the highest local CAEA reservation as the
        // floor. The route-level sequence lock makes this read+assignment atomic.
        if ($document?->authorization_type !== 'CAEA') {
            return $response;
        }

        $localMax = FiscalDocument::query()
            ->where('fiscal_company_id', $company->id)
            ->where('point_of_sale', $pointOfSale)
            ->where('voucher_type', $voucherType)
            ->where('authorization_type', 'CAEA')
            ->whereNotNull('document_number')
            ->max('document_number');

        $response['CbteNro'] = max((int) data_get($response, 'CbteNro', 0), (int) $localMax);

        return $response;
    }

    public function consult(FiscalCompany $company, AccessTicket $ticket, int $pointOfSale, int $voucherType, int $voucherNumber, ?FiscalDocument $document = null, ?string $traceId = null): array
    {
        return $this->inner->consult($company, $ticket, $pointOfSale, $voucherType, $voucherNumber, $document, $traceId);
    }

    public function requestCaea(FiscalCompany $company, AccessTicket $ticket, string $period, int $order, ?FiscalDocument $document = null, ?string $traceId = null): array
    {
        return $this->inner->requestCaea($company, $ticket, $period, $order, $document, $traceId);
    }

    public function consultCaea(FiscalCompany $company, AccessTicket $ticket, string $period, int $order, ?FiscalDocument $document = null, ?string $traceId = null): array
    {
        return $this->inner->consultCaea($company, $ticket, $period, $order, $document, $traceId);
    }

    public function reportCaea(FiscalCompany $company, AccessTicket $ticket, array $request, ?FiscalDocument $document = null, ?string $traceId = null): array
    {
        return $this->inner->reportCaea($company, $ticket, $request, $document, $traceId);
    }

    public function informCaeaWithoutMovement(FiscalCompany $company, AccessTicket $ticket, string $caea, int $pointOfSale, int $voucherType, ?string $traceId = null): array
    {
        return $this->inner->informCaeaWithoutMovement($company, $ticket, $caea, $pointOfSale, $voucherType, $traceId);
    }

    public function consultCaeaWithoutMovement(FiscalCompany $company, AccessTicket $ticket, string $caea, int $pointOfSale, int $voucherType, ?string $traceId = null): array
    {
        return $this->inner->consultCaeaWithoutMovement($company, $ticket, $caea, $pointOfSale, $voucherType, $traceId);
    }

    public function dummy(FiscalCompany $company, ?string $traceId = null): array
    {
        return $this->inner->dummy($company, $traceId);
    }

    public function activities(FiscalCompany $company, AccessTicket $ticket, ?string $traceId = null): array
    {
        return $this->inner->activities($company, $ticket, $traceId);
    }

    public function pointsOfSale(FiscalCompany $company, AccessTicket $ticket, ?string $traceId = null): array
    {
        return $this->inner->pointsOfSale($company, $ticket, $traceId);
    }
}
