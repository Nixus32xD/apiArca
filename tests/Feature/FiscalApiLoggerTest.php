<?php

use App\Models\FiscalApiLog;
use App\Services\Fiscal\Support\FiscalApiLogger;

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
});

it('redacts nested values raw escaped and namespaced XML secrets before logging', function (): void {
    $rawXml = '<soap:Envelope><Token>raw-token</Token><auth:Sign>raw-sign</auth:Sign><in0>signed-cms</in0><Auth token="attribute-token" /></soap:Envelope>';
    $escapedXml = '&lt;Token&gt;escaped-token&lt;/Token&gt;&lt;auth:Sign&gt;escaped-sign&lt;/auth:Sign&gt;&lt;Auth sign=&quot;escaped-attribute&quot; /&gt;';

    app(FiscalApiLogger::class)->outbound(
        'WSAA',
        'https://example.test/wsaa',
        microtime(true),
        ['token' => 'array-token', 'payload' => $rawXml, 'safe' => 'kept'],
        $escapedXml,
        200,
        new RuntimeException('Unexpected response: <Token>exception-token</Token> sign="exception-sign"'),
    );

    $log = FiscalApiLog::query()->sole();
    $request = (string) data_get($log->request_summary, 'payload');
    $response = (string) data_get($log->response_summary, 'payload');

    expect($request)->toContain('[redacted]')
        ->toContain('safe')
        ->not->toContain('raw-token')
        ->not->toContain('raw-sign')
        ->not->toContain('signed-cms')
        ->not->toContain('attribute-token')
        ->not->toContain('array-token')
        ->and($response)->toContain('[redacted]')
        ->not->toContain('escaped-token')
        ->not->toContain('escaped-sign')
        ->not->toContain('escaped-attribute')
        ->and((string) $log->error_message)->toContain('[redacted]')
        ->not->toContain('exception-token')
        ->not->toContain('exception-sign');
});

it('redacts authorization bearer credentials and PEM material embedded in free text', function (): void {
    app(FiscalApiLogger::class)->outbound(
        'FECAESolicitar',
        'https://example.test/wsfe',
        microtime(true),
        [
            'Authorization' => 'Bearer request-secret',
            'details' => 'Authorization: Bearer response-secret',
            'private_material' => "-----BEGIN PRIVATE KEY-----\nprivate-secret\n-----END PRIVATE KEY-----",
        ],
        'Authorization: Bearer upstream-secret; -----BEGIN CERTIFICATE-----certificate-secret-----END CERTIFICATE-----',
        502,
        new RuntimeException('Authorization: Bearer exception-secret password="exception-password"'),
    );

    $log = FiscalApiLog::query()->sole();
    $request = (string) data_get($log->request_summary, 'payload');
    $response = (string) data_get($log->response_summary, 'payload');

    expect($request)->toContain('Authorization')
        ->toContain('[redacted]')
        ->not->toContain('request-secret')
        ->not->toContain('response-secret')
        ->not->toContain('private-secret')
        ->and($response)->toContain('[redacted]')
        ->not->toContain('upstream-secret')
        ->not->toContain('certificate-secret')
        ->and((string) $log->error_message)->toContain('[redacted]')
        ->not->toContain('exception-secret')
        ->not->toContain('exception-password');
});
