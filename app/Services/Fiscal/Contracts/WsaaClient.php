<?php

namespace App\Services\Fiscal\Contracts;

use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Services\Fiscal\Data\AccessTicketData;

interface WsaaClient
{
    public function login(FiscalCompany $company, FiscalCredential $credential, string $service): AccessTicketData;
}
