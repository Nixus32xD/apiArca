<?php

use App\Exceptions\Fiscal\FiscalException;
use App\Models\AccessTicket;
use App\Models\FiscalApiLog;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\Wsfev1Client;

beforeEach(function (): void {
    if (config('database.default') === 'sqlite' && ! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('The pdo_sqlite extension is required to run fiscal feature tests with the default phpunit database.');
    }

    $this->artisan('migrate:fresh');

    config(['fiscal.api_tokens' => ['test-token']]);

    $this->wsfe = new class implements Wsfev1Client
    {
        public int $authorizeCalls = 0;

        public int $consultCalls = 0;

        public ?FiscalException $authorizeException = null;

        public ?FiscalException $consultException = null;

        public array $consultResponse = [
            'ResultGet' => [
                'CodAutorizacion' => '12345678901234',
                'FchVto' => '20260510',
            ],
        ];

        public function authorize($company, $ticket, array $feCaeRequest, $document = null, ?string $traceId = null): array
        {
            $this->authorizeCalls++;

            if ($this->authorizeException) {
                throw $this->authorizeException;
            }

            return [
                'FeCabResp' => [
                    'Resultado' => 'A',
                ],
                'FeDetResp' => [
                    'FECAEDetResponse' => [
                        'Resultado' => 'A',
                        'CAE' => '12345678901234',
                        'CAEFchVto' => '20260510',
                    ],
                ],
            ];
        }

        public function lastAuthorized($company, $ticket, int $pointOfSale, int $voucherType, $document = null, ?string $traceId = null): array
        {
            return ['CbteNro' => 10];
        }

        public function consult($company, $ticket, int $pointOfSale, int $voucherType, int $voucherNumber, $document = null, ?string $traceId = null): array
        {
            $this->consultCalls++;

            if ($this->consultException) {
                throw $this->consultException;
            }

            return $this->consultResponse;
        }

        public function requestCaea($company, $ticket, string $period, int $order, $document = null, ?string $traceId = null): array
        {
            return [
                'ResultGet' => [
                    'CAEA' => '12345678901234',
                    'Periodo' => $period,
                    'Orden' => $order,
                ],
            ];
        }

        public function consultCaea($company, $ticket, string $period, int $order, $document = null, ?string $traceId = null): array
        {
            return $this->requestCaea($company, $ticket, $period, $order, $document, $traceId);
        }

        public function reportCaea($company, $ticket, array $request, $document = null, ?string $traceId = null): array
        {
            return [
                'Resultado' => 'A',
            ];
        }

        public function informCaeaWithoutMovement($company, $ticket, string $caea, int $pointOfSale, int $voucherType, ?string $traceId = null): array
        {
            return [
                'Resultado' => 'A',
            ];
        }

        public function consultCaeaWithoutMovement($company, $ticket, string $caea, int $pointOfSale, int $voucherType, ?string $traceId = null): array
        {
            return [
                'Resultado' => 'A',
            ];
        }

        public function dummy($company, ?string $traceId = null): array
        {
            return [
                'AppServer' => 'OK',
                'DbServer' => 'OK',
                'AuthServer' => 'OK',
            ];
        }

        public function activities($company, $ticket, ?string $traceId = null): array
        {
            return [
                'ResultGet' => [
                    'Actividad' => [
                        ['Id' => '620100'],
                    ],
                ],
            ];
        }

        public function pointsOfSale($company, $ticket, ?string $traceId = null): array
        {
            return [
                'ResultGet' => [
                    'PtoVenta' => [
                        ['Nro' => '1', 'EmisionTipo' => 'CAE', 'Bloqueado' => 'N'],
                    ],
                ],
            ];
        }
    };

    $this->app->instance(Wsfev1Client::class, $this->wsfe);
});

it('issues a fiscal document and persists CAE without requiring customer email', function (): void {
    $company = fiscalCompanyWithTicket();

    $response = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalPayload($company->external_business_id));

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', 'authorized')
        ->assertJsonPath('data.number', 11)
        ->assertJsonPath('data.cae', '12345678901234')
        ->assertJsonPath('meta.idempotent_replay', false);

    $document = FiscalDocument::query()->firstOrFail();

    expect($document->status)->toBe('authorized')
        ->and($document->normalized_payload['customer']['doc_type'])->toBe(99)
        ->and($document->normalized_payload['customer']['doc_number'])->toBe(0);
});

it('stores the explicit SaaS origin and finds documents by origin', function (): void {
    $company = fiscalCompanyWithTicket();
    $payload = array_merge(fiscalPayload($company->external_business_id), [
        'sale_id' => 'S-000001',
        'origin_type' => 'sale',
        'origin_id' => '123',
        'idempotency_key' => 'idem-origin-123',
    ]);

    $response = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', $payload);

    $response
        ->assertCreated()
        ->assertJsonPath('data.origin.type', 'sale')
        ->assertJsonPath('data.origin.id', '123');

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/documents/by-origin?business_id={$company->external_business_id}&origin_type=sale&origin_id=123")
        ->assertOk()
        ->assertJsonPath('data.0.origin.type', 'sale')
        ->assertJsonPath('data.0.origin.id', '123')
        ->assertJsonPath('data.0.idempotency_key', 'idem-origin-123');
});

it('returns the existing document for the same idempotency key', function (): void {
    $company = fiscalCompanyWithTicket();
    $payload = fiscalPayload($company->external_business_id);

    $this->withToken('test-token')->postJson('/api/fiscal/documents', $payload)->assertCreated();

    $response = $this->withToken('test-token')->postJson('/api/fiscal/documents', $payload);

    $response
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.number', 11);

    expect(FiscalDocument::query()->count())->toBe(1)
        ->and($this->wsfe->authorizeCalls)->toBe(1);
});

it('marks HTTP 504 from ARCA as uncertain and stores the expected retry guidance', function (): void {
    $company = fiscalCompanyWithTicket();
    $this->wsfe->authorizeException = new FiscalException('raw upstream message', 502, 'arca_http_error', [
        'status_code' => 504,
    ]);

    $response = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalPayload($company->external_business_id));

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', 'uncertain')
        ->assertJsonPath('data.fiscal_status', 'uncertain')
        ->assertJsonPath('data.error.code', 'arca_http_error')
        ->assertJsonPath('data.error.message', 'La conexión con ARCA agotó el tiempo de espera. No se sabe si el comprobante fue procesado. Se debe consultar el comprobante antes de volver a emitir.');

    $document = FiscalDocument::query()->firstOrFail();

    expect($document->authorization_type)->toBe('CAE')
        ->and($document->raw_request)->not->toBeNull();
});

it('blocks retry when reconciliation is still uncertain', function (): void {
    $company = fiscalCompanyWithTicket();
    $this->wsfe->authorizeException = new FiscalException('timeout', 504, 'arca_timeout');

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalPayload($company->external_business_id))
        ->assertCreated()
        ->assertJsonPath('data.status', 'uncertain');

    $document = FiscalDocument::query()->firstOrFail();
    $this->wsfe->authorizeException = null;
    $this->wsfe->consultException = new FiscalException('timeout', 504, 'arca_timeout');

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$document->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'reconcile_required_before_retry');

    expect($this->wsfe->consultCalls)->toBe(1)
        ->and($this->wsfe->authorizeCalls)->toBe(1);
});

it('retries safely with the same number when reconciliation says ARCA does not have the voucher', function (): void {
    $company = fiscalCompanyWithTicket();
    $this->wsfe->authorizeException = new FiscalException('timeout', 504, 'arca_timeout');

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalPayload($company->external_business_id))
        ->assertCreated()
        ->assertJsonPath('data.status', 'uncertain');

    $document = FiscalDocument::query()->firstOrFail();
    $this->wsfe->authorizeException = null;
    $this->wsfe->consultResponse = [
        'Errors' => [
            'Err' => [
                'Code' => '602',
                'Msg' => 'Comprobante inexistente',
            ],
        ],
    ];

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$document->id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'authorized')
        ->assertJsonPath('data.number', 11)
        ->assertJsonPath('meta.reconciled_before_retry', true);

    expect($this->wsfe->consultCalls)->toBe(1)
        ->and($this->wsfe->authorizeCalls)->toBe(2);
});

it('requests and consults CAEA for a fiscal company', function (): void {
    $company = fiscalCompanyWithTicket();

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/companies/{$company->external_business_id}/caea/request", [
            'period' => '202604',
            'order' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('data.caea.code', '12345678901234')
        ->assertJsonPath('data.caea.period', '202604')
        ->assertJsonPath('data.caea.order', 1);

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/companies/{$company->external_business_id}/caea/consult?period=202604&order=1")
        ->assertOk()
        ->assertJsonPath('data.caea.code', '12345678901234');
});

it('issues a CAEA document and reports it immediately by default', function (): void {
    $company = fiscalCompanyWithTicket();
    $payload = array_merge(fiscalPayload($company->external_business_id), [
        'authorization_type' => 'CAEA',
        'idempotency_key' => 'idem-caea-100',
        'caea' => [
            'code' => '12345678901234',
            'period' => '202604',
            'order' => 1,
            'from' => 20260401,
            'to' => 20260415,
            'due_date' => '2026-04-15',
            'report_deadline' => '2026-04-20',
        ],
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'authorized')
        ->assertJsonPath('data.fiscal_status', 'reported')
        ->assertJsonPath('data.authorization_type', 'CAEA')
        ->assertJsonPath('data.authorization_code', '12345678901234')
        ->assertJsonPath('data.number', 11)
        ->assertJsonPath('data.caea.period', '202604')
        ->assertJsonPath('data.caea.order', 1);

    $document = FiscalDocument::query()->firstOrFail();

    expect($document->raw_request['FeDetReq']['FECAEADetRequest'][0]['CAEA'])->toBe('12345678901234')
        ->and($document->attempts()->where('operation', 'FECAEARegInformativo')->exists())->toBeTrue();
});

it('keeps a CAEA document pending report when requested', function (): void {
    $company = fiscalCompanyWithTicket();
    $payload = array_merge(fiscalPayload($company->external_business_id), [
        'authorization_type' => 'CAEA',
        'idempotency_key' => 'idem-caea-pending',
        'caea' => [
            'code' => '12345678901234',
            'report_now' => false,
        ],
    ]);

    $response = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'authorized')
        ->assertJsonPath('data.fiscal_status', 'pending_report')
        ->assertJsonPath('data.authorization_type', 'CAEA');

    $documentId = $response->json('data.id');

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$documentId}/caea/report")
        ->assertOk()
        ->assertJsonPath('data.fiscal_status', 'reported');
});

it('rejects fiscal API calls without the internal token', function (): void {
    fiscalCompanyWithTicket();

    $this
        ->postJson('/api/fiscal/documents', fiscalPayload('business-1'))
        ->assertUnauthorized();

    expect(FiscalApiLog::query()->count())->toBe(1);
});

it('returns normalized company status for the SaaS fiscal dashboard', function (): void {
    $company = fiscalCompanyWithTicket();

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/companies/{$company->external_business_id}/status")
        ->assertOk()
        ->assertJsonPath('data.business_id', $company->external_business_id)
        ->assertJsonPath('data.ready', true)
        ->assertJsonPath('data.status_label', 'Listo')
        ->assertJsonPath('data.message', 'Empresa fiscal operativa.')
        ->assertJsonPath('data.credential.csr_generated', false)
        ->assertJsonPath('data.credential.certificate_loaded', true);
});

it('normalizes fiscal activities and points of sale for the SaaS', function (): void {
    $company = fiscalCompanyWithTicket();

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/companies/{$company->external_business_id}/activities")
        ->assertOk()
        ->assertJsonPath('data.activities.0.id', 620100)
        ->assertJsonPath('data.activities.0.code', 620100);

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/companies/{$company->external_business_id}/points-of-sale")
        ->assertOk()
        ->assertJsonPath('data.points_of_sale.0.id', 1)
        ->assertJsonPath('data.points_of_sale.0.number', 1)
        ->assertJsonPath('data.points_of_sale.0.type', 'CAE')
        ->assertJsonPath('data.points_of_sale.0.emission_type', 'CAE')
        ->assertJsonPath('data.points_of_sale.0.blocked', false);
});

it('generates a pending credential CSR and reuses the same key name', function (): void {
    $company = fiscalCompanyForCredentialOnboarding();
    $payload = [
        'key_name' => 'empresa-demo.key',
        'common_name' => 'empresa-demo-prod',
        'organization_name' => 'Empresa Demo SA',
    ];

    $response = $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/companies/{$company->external_business_id}/credentials/csr", $payload);

    $response
        ->assertCreated()
        ->assertJsonPath('meta.created', true)
        ->assertJsonPath('data.credential.key_name', 'empresa-demo.key')
        ->assertJsonPath('data.credential.status', 'pending_certificate')
        ->assertJsonPath('data.credential.active', false);

    $credentialId = $response->json('data.credential.id');
    $csr = $response->json('data.csr');

    expect($csr)->toStartWith('-----BEGIN CERTIFICATE REQUEST-----')
        ->and(FiscalCredential::query()->count())->toBe(1);

    $reused = $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/companies/{$company->external_business_id}/credentials/csr", $payload);

    $reused
        ->assertOk()
        ->assertJsonPath('meta.created', false)
        ->assertJsonPath('data.credential.id', $credentialId)
        ->assertJsonPath('data.csr', $csr);

    expect(FiscalCredential::query()->count())->toBe(1);
});

it('stores a returned ARCA certificate only when it matches the generated private key', function (): void {
    $company = fiscalCompanyForCredentialOnboarding('business-cert');

    $response = $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/companies/{$company->external_business_id}/credentials/csr", [
            'key_name' => 'business-cert.key',
        ]);

    $credential = FiscalCredential::query()->findOrFail($response->json('data.credential.id'));
    $certificate = fiscalCertificateForCredential($credential);

    $this
        ->withToken('test-token')
        ->putJson("/api/fiscal/companies/{$company->external_business_id}/credentials/{$credential->id}/certificate", [
            'certificate' => $certificate,
        ])
        ->assertOk()
        ->assertJsonPath('data.credential.status', 'active')
        ->assertJsonPath('data.credential.active', true);

    $credential->refresh();

    expect($credential->certificate)->toBe(trim($certificate))
        ->and($credential->certificate_expires_at)->not->toBeNull()
        ->and($credential->status)->toBe('active')
        ->and($credential->active)->toBeTrue();
});

it('rejects an ARCA certificate that does not match the generated private key', function (): void {
    $company = fiscalCompanyForCredentialOnboarding('business-mismatch');

    $response = $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/companies/{$company->external_business_id}/credentials/csr", [
            'key_name' => 'business-mismatch.key',
        ]);

    $credential = FiscalCredential::query()->findOrFail($response->json('data.credential.id'));
    $certificate = fiscalCertificateForNewKey();

    $this
        ->withToken('test-token')
        ->putJson("/api/fiscal/companies/{$company->external_business_id}/credentials/{$credential->id}/certificate", [
            'certificate' => $certificate,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'certificate_private_key_mismatch');

    expect($credential->refresh()->status)->toBe('pending_certificate')
        ->and($credential->active)->toBeFalse();
});

function fiscalCompanyWithTicket(): FiscalCompany
{
    $company = FiscalCompany::query()->create([
        'external_business_id' => 'business-1',
        'cuit' => '20123456789',
        'legal_name' => 'Empresa Demo SA',
        'environment' => 'testing',
        'default_point_of_sale' => 1,
        'default_voucher_type' => 6,
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

function fiscalCompanyForCredentialOnboarding(string $businessId = 'business-csr'): FiscalCompany
{
    return FiscalCompany::query()->create([
        'external_business_id' => $businessId,
        'cuit' => '20123456789',
        'legal_name' => 'Empresa Demo SA',
        'environment' => 'testing',
        'default_point_of_sale' => 1,
        'default_voucher_type' => 6,
        'enabled' => true,
    ]);
}

function fiscalCertificateForCredential(FiscalCredential $credential): string
{
    $privateKey = openssl_pkey_get_private($credential->private_key);

    if ($privateKey === false) {
        throw new RuntimeException('Could not open generated private key for test certificate.');
    }

    $certificate = openssl_csr_sign($credential->csr, null, $privateKey, 365, fiscalOpenSslConfig());

    if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem)) {
        throw new RuntimeException('Could not sign test certificate.');
    }

    return $certificatePem;
}

function fiscalCertificateForNewKey(): string
{
    $config = fiscalOpenSslConfig();
    $privateKey = openssl_pkey_new($config);

    if ($privateKey === false) {
        throw new RuntimeException('Could not generate mismatched test private key.');
    }

    $csr = openssl_csr_new([
        'countryName' => 'AR',
        'organizationName' => 'Empresa Demo SA',
        'commonName' => 'mismatch',
        'serialNumber' => 'CUIT 20123456789',
    ], $privateKey, $config);

    if ($csr === false) {
        throw new RuntimeException('Could not generate mismatched test CSR.');
    }

    $certificate = openssl_csr_sign($csr, null, $privateKey, 365, $config);

    if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem)) {
        throw new RuntimeException('Could not sign mismatched test certificate.');
    }

    return $certificatePem;
}

/**
 * @return array<string, mixed>
 */
function fiscalOpenSslConfig(): array
{
    $config = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'digest_alg' => 'sha256',
    ];

    $candidate = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

    if (is_file($candidate)) {
        $config['config'] = $candidate;
    }

    return $config;
}

/**
 * @return array<string, mixed>
 */
function fiscalPayload(string $businessId): array
{
    return [
        'business_id' => $businessId,
        'sale_id' => 'sale-100',
        'document_type' => 'invoice_b',
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
        'idempotency_key' => 'idem-100',
        'metadata' => [
            'source' => 'tests',
        ],
    ];
}
