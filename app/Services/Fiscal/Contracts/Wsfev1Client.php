<?php

namespace App\Services\Fiscal\Contracts;

use App\Models\AccessTicket;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;

interface Wsfev1Client
{
    /**
     * @param  array<string, mixed>  $feCaeRequest
     * @return array<string, mixed>
     */
    public function authorize(
        FiscalCompany $company,
        AccessTicket $ticket,
        array $feCaeRequest,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function lastAuthorized(
        FiscalCompany $company,
        AccessTicket $ticket,
        int $pointOfSale,
        int $voucherType,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function consult(
        FiscalCompany $company,
        AccessTicket $ticket,
        int $pointOfSale,
        int $voucherType,
        int $voucherNumber,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function dummy(FiscalCompany $company, ?string $traceId = null): array;

    /**
     * @return array<string, mixed>
     */
    public function activities(FiscalCompany $company, AccessTicket $ticket, ?string $traceId = null): array;

    /**
     * @return array<string, mixed>
     */
    public function pointsOfSale(FiscalCompany $company, AccessTicket $ticket, ?string $traceId = null): array;
}
