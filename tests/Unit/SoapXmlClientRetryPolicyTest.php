<?php

use App\Services\Fiscal\Support\FiscalApiLogger;
use App\Services\Fiscal\Support\SoapXmlClient;
use App\Services\Fiscal\Support\XmlParser;

it('never enables blind transport retries for ARCA mutations', function (): void {
    $client = new SoapXmlClient(app(XmlParser::class), app(FiscalApiLogger::class));

    expect($client->transportRetryAllowed('FECAESolicitar'))->toBeFalse()
        ->and($client->transportRetryAllowed('FECAEASolicitar'))->toBeFalse()
        ->and($client->transportRetryAllowed('FECAEARegInformativo'))->toBeFalse()
        ->and($client->transportRetryAllowed('FECAEASinMovimientoInformar'))->toBeFalse()
        ->and($client->transportRetryAllowed('FECompConsultar'))->toBeTrue();
});
