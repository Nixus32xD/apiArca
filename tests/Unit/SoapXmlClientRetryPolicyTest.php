<?php

use App\Services\Fiscal\Support\FiscalApiLogger;
use App\Services\Fiscal\Support\SoapXmlClient;
use App\Services\Fiscal\Support\XmlParser;

it('never allows blind transport retry for fiscal mutations', function (): void {
    $client = new SoapXmlClient(
        app(XmlParser::class),
        app(FiscalApiLogger::class),
    );

    expect($client->transportRetryAllowed('FECAESolicitar'))->toBeFalse()
        ->and($client->transportRetryAllowed('FECAEASolicitar'))->toBeFalse()
        ->and($client->transportRetryAllowed('FECAEARegInformativo'))->toBeFalse()
        ->and($client->transportRetryAllowed('FECAEASinMovimientoInformar'))->toBeFalse();
});

it('keeps transport retry available for read only WSFE operations', function (): void {
    $client = new SoapXmlClient(
        app(XmlParser::class),
        app(FiscalApiLogger::class),
    );

    expect($client->transportRetryAllowed('FECompUltimoAutorizado'))->toBeTrue()
        ->and($client->transportRetryAllowed('FECompConsultar'))->toBeTrue()
        ->and($client->transportRetryAllowed('FECAEAConsultar'))->toBeTrue()
        ->and($client->transportRetryAllowed('FECAEASinMovimientoConsultar'))->toBeTrue()
        ->and($client->transportRetryAllowed('FEDummy'))->toBeTrue();
});
