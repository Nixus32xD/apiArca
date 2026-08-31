<?php

use App\Exceptions\Fiscal\FiscalException;
use App\Models\AccessTicket;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    if (config('database.default') === 'sqlite' && ! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('The pdo_sqlite extension is required to run fiscal feature tests with the default phpunit database.');
    }

    $this->artisan('migrate:fresh');

    config([
        'fiscal.api_tokens' => ['test-token'],
        'fiscal-sequence.store' => 'array',
        'fiscal-sequence.ttl_seconds' => 60,
        'fiscal-sequence.wait_seconds' => 0,
    ]);

    $this->sequenceWsfe = new class implements Wsfev1Client
    {
        public int $lastNumber = 10;
        public int $authorizeCalls = 0;
        public int $consultCalls = 0;
        public array $authorizeNumbers = [];
        public ?FiscalException $authorizeException = null;

        public function authorize($company, $ticket, array $feCaeRequest, $document = null, ?string $traceId = null): array
        {
            $this->authorizeCalls++;
            $number = (int) ($document?->document_number ?? 0);
            $this->authorizeNumbers[] = $number;

            if ($this->authorizeException) {
                throw $this->authorizeException;
            }

            $this->lastNumber = max($this->lastNumber, $number);

            return [
                'FeCabResp' => ['Resultado' => 'A'],
                'FeDetResp' => [
                    'FECAEDetResponse' => [
                        'Resultado' => 'A',
                        'CAE' => '12345678901234',
                        'CAEFchVto' => '20260910',
                    ],
                ],
            ];
        }

        public function lastAuthorized($company, $ticket, int $pointOfSale, int $voucherType, $document = null, ?string $traceId = null): array
        {
            return ['CbteNro' => $this->lastNumber];
        }

        public function consult($company, $ticket, int $pointOfSale, int $voucherType, int $voucherNumber, $document = null, ?string $traceId = null): array
        {
            $this->consultCalls++;

            return [
                'ResultGet' => [
                    'CodAutorizacion' => '12345678901234',
                    'FchVto' => '20260910',
                    'Resultado' => 'A',
                ],
            ];
        }

        public function requestCaea($company, $ticket, string $period, int $order, $document = null, ?string $traceId = null): array
        {
            return ['ResultGet' => ['CAEA' => '12345678901234', 'Periodo' => $period, 'Orden' => $order]];
        }

        public function consultCaea($company, $ticket, string $period, int $order, $document = null, ?string $traceId = null): array
        {
            return $this->requestCaea($company, $ticket, $period, $order, $document, $traceId);
        }

        public function reportCaea($company, $ticket, array $request, $document = null, ?string $traceId = null): array
        {
            return ['Resultado' => 'A'];
        }

        public function informCaeaWithoutMovement($company, $ticket, string $caea, int $pointOfSale, int $voucherType, ?string $traceId = null): array
        {
            return ['Resultado' => 'A'];
        }

        public function consultCaeaWithoutMovement($company, $ticket, string $caea, int $pointOfSale, int $voucherType, ?string $traceId = null): array
        {
            return ['Resultado' => 'A'];
        }

        public function dummy($company, ?string $traceId = null): array
        {
            return ['AppServer' => 'OK', 'DbServer' => 'OK', 'AuthServer' => 'OK'];
        }

        public function activities($company, $ticket, ?string $traceId = null): array
        {
            return ['ResultGet' => ['Actividad' => []]];
        }

        public function pointsOfSale($company, $ticket, ?string $traceId = null): array
        {
            return ['ResultGet' => ['PtoVenta' => []]];
        }
    };

    $this->app->instance(Wsfev1Client::class, $this->sequenceWsfe);
});

it('allocates different consecutive numbers for documents in the same fiscal sequence', function (): void {
    $company = sequenceFiscalCompany('business-sequence-1', '20123456789');

    $first = $this->withToken('test-token')->postJson(
        '/api/fiscal/documents',
        sequenceFiscalPayload($company, 'idem-sequence-1'),
    );
    $second = $this->withToken('test-token')->postJson(
        '/api/fiscal/documents',
        sequenceFiscalPayload($company, 'idem-sequence-2'),
    );

    $first->assertCreated()->assertJsonPath('data.number', 11);
    $second->assertCreated()->assertJsonPath('data.number', 12);

    expect($this->sequenceWsfe->authorizeNumbers)->toBe([11, 12])
        ->and(FiscalDocument::query()->where('fiscal_company_id', $company->id)->pluck('document_number')->all())
        ->toBe([11, 12]);
});

it('fails closed when the same fiscal sequence lock cannot be acquired', function (): void {
    $company = sequenceFiscalCompany('business-busy', '20123456789');
    $lock = Cache::store('array')->lock(sequenceLockKey($company, 1, 11), 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->withToken('test-token')
            ->postJson('/api/fiscal/documents', sequenceFiscalPayload($company, 'idem-busy'))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'fiscal_sequence_busy');

        expect($this->sequenceWsfe->authorizeCalls)->toBe(0)
            ->and(FiscalDocument::query()->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('does not let one company sequence lock block another company', function (): void {
    $companyA = sequenceFiscalCompany('business-a', '20123456789');
    $companyB = sequenceFiscalCompany('business-b', '20333444559');
    $lock = Cache::store('array')->lock(sequenceLockKey($companyA, 1, 11), 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->withToken('test-token')
            ->postJson('/api/fiscal/documents', sequenceFiscalPayload($companyB, 'idem-company-b'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'authorized');

        expect($this->sequenceWsfe->authorizeCalls)->toBe(1);
    } finally {
        $lock->release();
    }
});

it('does not let one point of sale lock block another point of sale', function (): void {
    $company = sequenceFiscalCompany('business-pos', '20123456789');
    $lock = Cache::store('array')->lock(sequenceLockKey($company, 1, 11), 60);
    expect($lock->get())->toBeTrue();

    try {
        $payload = sequenceFiscalPayload($company, 'idem-pos-2');
        $payload['point_of_sale'] = 2;

        $this->withToken('test-token')
            ->postJson('/api/fiscal/documents', $payload)
            ->assertCreated()
            ->assertJsonPath('data.point_of_sale', 2);

        expect($this->sequenceWsfe->authorizeCalls)->toBe(1);
    } finally {
        $lock->release();
    }
});

it('blocks a new logical issue while the previous numbered voucher is uncertain', function (): void {
    $company = sequenceFiscalCompany('business-uncertain', '20123456789');
    $this->sequenceWsfe->authorizeException = new FiscalException('timeout', 504, 'arca_timeout');

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', sequenceFiscalPayload($company, 'idem-uncertain-1'))
        ->assertCreated()
        ->assertJsonPath('data.status', 'uncertain')
        ->assertJsonPath('data.number', 11);

    $this->sequenceWsfe->authorizeException = null;

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', sequenceFiscalPayload($company, 'idem-uncertain-2'))
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'fiscal_sequence_requires_reconcile');

    expect($this->sequenceWsfe->authorizeCalls)->toBe(1)
        ->and(FiscalDocument::query()->count())->toBe(1);
});

it('allows the same idempotency key to replay an uncertain document without a second authorization', function (): void {
    $company = sequenceFiscalCompany('business-idem-uncertain', '20123456789');
    $this->sequenceWsfe->authorizeException = new FiscalException('timeout', 504, 'arca_timeout');
    $payload = sequenceFiscalPayload($company, 'idem-same-uncertain');

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'uncertain');

    $this->sequenceWsfe->authorizeException = null;

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', $payload)
        ->assertCreated()
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.status', 'uncertain');

    expect($this->sequenceWsfe->authorizeCalls)->toBe(1)
        ->and(FiscalDocument::query()->count())->toBe(1);
});

function sequenceFiscalCompany(string $businessId, string $cuit): FiscalCompany
{
    $company = FiscalCompany::query()->create([
        'external_business_id' => $businessId,
        'cuit' => $cuit,
        'legal_name' => 'Empresa '.$businessId,
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

/**
 * @return array<string, mixed>
 */
function sequenceFiscalPayload(FiscalCompany $company, string $idempotencyKey): array
{
    return [
        'business_id' => $company->external_business_id,
        'sale_id' => $idempotencyKey,
        'document_type' => 'invoice_c',
        'concept' => 1,
        'amounts' => [
            'imp_total' => 121,
            'imp_neto' => 100,
            'imp_iva' => 21,
            'imp_trib' => 0,
            'imp_op_ex' => 0,
            'imp_tot_conc' => 0,
        ],
        'currency' => 'PES',
        'currency_rate' => 1,
        'idempotency_key' => $idempotencyKey,
    ];
}

function sequenceLockKey(FiscalCompany $company, int $pointOfSale, int $voucherType): string
{
    return "fiscal:issue:{$company->id}:{$company->environment}:{$pointOfSale}:{$voucherType}";
}
