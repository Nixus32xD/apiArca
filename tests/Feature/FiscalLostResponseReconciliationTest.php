<?php

use App\Models\AccessTicket;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\Wsfev1Client;

beforeEach(function (): void {
    if (config('database.default') === 'sqlite' && ! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('The pdo_sqlite extension is required to run fiscal feature tests with the default phpunit database.');
    }

    $this->artisan('migrate:fresh');
    config([
        'fiscal.api_tokens' => ['test-token'],
        'fiscal-sequence.store' => 'array',
        'fiscal-sequence.wait_seconds' => 0,
    ]);

    $this->lostResponseWsfe = new class implements Wsfev1Client
    {
        public int $authorizeCalls = 0;
        public int $consultCalls = 0;

        public function authorize($company, $ticket, array $feCaeRequest, $document = null, ?string $traceId = null): array
        {
            $this->authorizeCalls++;
            throw new RuntimeException('Reconciliation must not authorize again.');
        }

        public function lastAuthorized($company, $ticket, int $pointOfSale, int $voucherType, $document = null, ?string $traceId = null): array
        {
            return ['CbteNro' => 126];
        }

        public function consult($company, $ticket, int $pointOfSale, int $voucherType, int $voucherNumber, $document = null, ?string $traceId = null): array
        {
            $this->consultCalls++;

            return [
                'ResultGet' => [
                    'Resultado' => 'A',
                    'CodAutorizacion' => '86123456789012',
                    'FchVto' => '20260910',
                    'CbteDesde' => $voucherNumber,
                    'CbteHasta' => $voucherNumber,
                ],
            ];
        }

        public function requestCaea($company, $ticket, string $period, int $order, $document = null, ?string $traceId = null): array
        {
            return [];
        }

        public function consultCaea($company, $ticket, string $period, int $order, $document = null, ?string $traceId = null): array
        {
            return [];
        }

        public function reportCaea($company, $ticket, array $request, $document = null, ?string $traceId = null): array
        {
            return [];
        }

        public function informCaeaWithoutMovement($company, $ticket, string $caea, int $pointOfSale, int $voucherType, ?string $traceId = null): array
        {
            return [];
        }

        public function consultCaeaWithoutMovement($company, $ticket, string $caea, int $pointOfSale, int $voucherType, ?string $traceId = null): array
        {
            return [];
        }

        public function dummy($company, ?string $traceId = null): array
        {
            return [];
        }

        public function activities($company, $ticket, ?string $traceId = null): array
        {
            return [];
        }

        public function pointsOfSale($company, $ticket, ?string $traceId = null): array
        {
            return [];
        }
    };

    $this->app->instance(Wsfev1Client::class, $this->lostResponseWsfe);
});

it('recovers an authorized voucher by FECompConsultar without sending FECAESolicitar again', function (): void {
    $company = lostResponseCompany();
    $document = FiscalDocument::query()->create([
        'fiscal_company_id' => $company->id,
        'origin_type' => 'sale',
        'origin_id' => 'sale-lost-response',
        'document_type' => 'invoice_c',
        'point_of_sale' => 1,
        'voucher_type' => 11,
        'concept' => 1,
        'document_number' => 126,
        'status' => 'uncertain',
        'authorization_type' => 'CAE',
        'idempotency_key' => 'idem-lost-response',
        'normalized_payload' => [
            'amounts' => [
                'imp_total' => '121.00',
                'imp_neto' => '121.00',
                'imp_iva' => '0.00',
                'iva_items' => [],
            ],
        ],
        'error_code' => 'arca_timeout',
        'error_message' => 'La respuesta original se perdio.',
    ]);

    $this->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$document->id}/reconcile", [
            'business_id' => $company->external_business_id,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'authorized')
        ->assertJsonPath('data.number', 126)
        ->assertJsonPath('data.cae', '86123456789012');

    expect($this->lostResponseWsfe->consultCalls)->toBe(1)
        ->and($this->lostResponseWsfe->authorizeCalls)->toBe(0)
        ->and($document->refresh()->status)->toBe('authorized')
        ->and($document->cae)->toBe('86123456789012');
});

function lostResponseCompany(): FiscalCompany
{
    $company = FiscalCompany::query()->create([
        'external_business_id' => 'business-lost-response',
        'cuit' => '20123456789',
        'legal_name' => 'Empresa Lost Response',
        'fiscal_condition' => 'monotributo',
        'environment' => 'testing',
        'default_point_of_sale' => 1,
        'default_voucher_type' => 11,
        'enabled' => true,
    ]);

    FiscalCredential::query()->create([
        'fiscal_company_id' => $company->id,
        'certificate' => '-----BEGIN CERTIFICATE-----fake-----END CERTIFICATE-----',
        'private_key' => '-----BEGIN PRIVATE KEY-----fake-----END PRIVATE KEY-----',
        'active' => true,
        'certificate_expires_at' => now()->addYear(),
    ]);

    AccessTicket::query()->create([
        'fiscal_company_id' => $company->id,
        'service' => 'wsfe',
        'token' => 'token',
        'sign' => 'sign',
        'generation_time' => now()->subMinute(),
        'expiration_time' => now()->addHours(2),
    ]);

    return $company;
}
