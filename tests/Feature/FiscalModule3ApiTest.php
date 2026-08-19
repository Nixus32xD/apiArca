<?php

use App\Jobs\SendFiscalDocumentEmailJob;
use App\Models\AccessTicket;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Models\FiscalPurchase;
use App\Models\FiscalPurchaseAttachment;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use App\Services\Fiscal\FiscalDocumentPdfService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    if (config('database.default') === 'sqlite' && ! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('The pdo_sqlite extension is required to run fiscal feature tests with the default phpunit database.');
    }

    $this->artisan('migrate:fresh');
    Storage::fake('local');
    config([
        'fiscal.api_tokens' => ['test-token'],
        'fiscal.documents.disk' => 'local',
        'fiscal.attachments.disk' => 'local',
        'fiscal.security.require_company_scope_for_id_routes' => false,
    ]);

    $this->wsfe = new class implements Wsfev1Client
    {
        public int $authorizeCalls = 0;

        public function authorize($company, $ticket, array $feCaeRequest, $document = null, ?string $traceId = null): array
        {
            $this->authorizeCalls++;

            return [
                'FeCabResp' => ['Resultado' => 'A'],
                'FeDetResp' => [
                    'FECAEDetResponse' => [
                        'Resultado' => 'A',
                        'CAE' => '12345678901234',
                        'CAEFchVto' => '20260831',
                    ],
                ],
            ];
        }

        public function lastAuthorized($company, $ticket, int $pointOfSale, int $voucherType, $document = null, ?string $traceId = null): array
        {
            return ['CbteNro' => 20];
        }

        public function consult($company, $ticket, int $pointOfSale, int $voucherType, int $voucherNumber, $document = null, ?string $traceId = null): array
        {
            return ['ResultGet' => ['CodAutorizacion' => '12345678901234', 'FchVto' => '20260831']];
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
            return ['ResultGet' => ['Actividad' => [['Id' => '620100']]]];
        }

        public function pointsOfSale($company, $ticket, ?string $traceId = null): array
        {
            return ['ResultGet' => ['PtoVenta' => [['Nro' => '1', 'EmisionTipo' => 'CAE', 'Bloqueado' => 'N']]]];
        }
    };

    $this->app->instance(Wsfev1Client::class, $this->wsfe);
});

it('generates official ARCA QR payload and deterministic PDF only for authorized documents', function (): void {
    $company = module3FiscalCompany('business-pdf', ['cuit' => '20123456789']);

    $documentId = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', module3FiscalPayload($company->external_business_id, [
            'idempotency_key' => 'pdf-qr-1',
        ]))
        ->assertCreated()
        ->json('data.id');

    $qr = $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/documents/{$documentId}/qr?business_id={$company->external_business_id}")
        ->assertOk()
        ->assertJsonPath('data.payload.ver', 1)
        ->assertJsonPath('data.payload.cuit', 20123456789)
        ->assertJsonPath('data.payload.tipoCodAut', 'E')
        ->assertJsonPath('data.payload.codAut', 12345678901234)
        ->json('data');

    expect($qr['url'])->toStartWith('https://www.arca.gob.ar/fe/qr/?p=');
    $decoded = json_decode(base64_decode(parse_url($qr['url'], PHP_URL_QUERY) ? substr(parse_url($qr['url'], PHP_URL_QUERY), 2) : ''), true);
    expect($decoded['nroCmp'])->toBe(21);

    $firstPdf = $this
        ->withToken('test-token')
        ->get("/api/fiscal/documents/{$documentId}/pdf?business_id={$company->external_business_id}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->getContent();

    $secondPdf = $this
        ->withToken('test-token')
        ->get("/api/fiscal/documents/{$documentId}/pdf?business_id={$company->external_business_id}")
        ->assertOk()
        ->getContent();

    expect($firstPdf)->toStartWith('%PDF')
        ->and(hash('sha256', $firstPdf))->toBe(hash('sha256', $secondPdf));

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/documents/{$documentId}?business_id={$company->external_business_id}")
        ->assertOk()
        ->assertJsonMissingPath('data.pdf.storage_key')
        ->assertJsonPath('data.pdf.sha256', hash('sha256', $secondPdf));

    $draft = module3AuthorizedDocument($company, ['status' => 'draft', 'authorization_code' => null]);

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/documents/{$draft->id}/qr?business_id={$company->external_business_id}")
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'document_not_authorized');
});

it('queues fiscal document email and resends without issuing a new voucher', function (): void {
    $company = module3FiscalCompany('business-email');

    $documentId = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', module3FiscalPayload($company->external_business_id, [
            'idempotency_key' => 'email-1',
            'customer' => ['email' => 'cliente@example.test'],
        ]))
        ->assertCreated()
        ->json('data.id');

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$documentId}/email", [
            'business_id' => $company->external_business_id,
        ])
        ->assertAccepted()
        ->assertJsonPath('data.email.status', 'sent')
        ->assertJsonPath('data.email.attempts', 1);

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$documentId}/email/resend", [
            'business_id' => $company->external_business_id,
            'email' => 'otro@example.test',
        ])
        ->assertAccepted()
        ->assertJsonPath('data.email.status', 'sent')
        ->assertJsonPath('data.email.to', 'otro@example.test')
        ->assertJsonPath('data.email.attempts', 2);

    expect($this->wsfe->authorizeCalls)->toBe(1)
        ->and(FiscalDocument::query()->count())->toBe(1);
});

it('marks fiscal document email failures for queue retry without resending already sent emails', function (): void {
    $company = module3FiscalCompany('business-email-retry');
    $document = module3AuthorizedDocument($company, [
        'email_to' => 'cliente@example.test',
        'email_status' => 'pending',
        'email_attempts' => 0,
    ]);
    $pdfService = Mockery::mock(FiscalDocumentPdfService::class);
    $pdfService
        ->shouldReceive('store')
        ->once()
        ->andThrow(new RuntimeException('transient pdf failure'));

    $job = new SendFiscalDocumentEmailJob($document->id);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300])
        ->and(fn () => $job->handle($pdfService))->toThrow(RuntimeException::class, 'transient pdf failure');

    $document->refresh();

    expect($document->email_status)->toBe('failed')
        ->and($document->email_attempts)->toBe(1)
        ->and($document->email_last_error)->toBe('transient pdf failure');

    $sent = module3AuthorizedDocument($company, [
        'email_to' => 'cliente@example.test',
        'email_status' => 'sent',
        'email_attempts' => 4,
        'email_sent_at' => now(),
    ]);
    $sentPdfService = Mockery::mock(FiscalDocumentPdfService::class);
    $sentPdfService->shouldNotReceive('store');

    (new SendFiscalDocumentEmailJob($sent->id))->handle($sentPdfService);

    expect($sent->refresh()->email_attempts)->toBe(4);
});

it('stores extended purchases with IVA 21 10.5 27 perceptions payment status and idempotency', function (): void {
    $company = module3FiscalCompany('business-purchases');
    $payload = module3PurchasePayload($company->external_business_id, [
        'idempotency_key' => 'purchase-idem-1',
    ]);

    $response = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/purchases', $payload)
        ->assertCreated()
        ->assertJsonPath('meta.idempotent_replay', false)
        ->assertJsonPath('data.category', 'insumos')
        ->assertJsonPath('data.concept', 'Productos profesionales')
        ->assertJsonPath('data.payment.status', 'paid')
        ->assertJsonPath('data.payment.due_date', '2026-08-30')
        ->assertJsonPath('data.amounts.imp_iva', '58.50')
        ->assertJsonPath('data.amounts.imp_trib', '5.00');

    expect(collect($response->json('data.amounts.iva_items'))->pluck('id')->sort()->values()->all())
        ->toBe([4, 5, 6]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/purchases', $payload)
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.id', $response->json('data.id'));

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/purchases', [
            ...$payload,
            'amounts' => [
                ...$payload['amounts'],
                'imp_total' => 364,
            ],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'idempotency_payload_conflict');

    expect(FiscalPurchase::query()->count())->toBe(1);
});

it('rejects inconsistent purchase totals including taxes and perceptions', function (): void {
    $company = module3FiscalCompany('business-invalid-total');

    $payload = module3PurchasePayload($company->external_business_id, [
        'idempotency_key' => 'bad-total',
        'amounts' => [
            'imp_total' => 350,
            'imp_neto' => 300,
            'imp_iva' => 58.5,
            'imp_trib' => 5,
            'imp_op_ex' => 0,
            'imp_tot_conc' => 0,
            'iva_items' => [
                ['id' => 5, 'base_imp' => 100, 'importe' => 21],
                ['id' => 4, 'base_imp' => 100, 'importe' => 10.5],
                ['id' => 6, 'base_imp' => 100, 'importe' => 27],
            ],
            'trib_items' => [
                ['id' => 99, 'desc' => 'Percepcion IIBB', 'base_imp' => 300, 'alic' => 1.6667, 'importe' => 5],
            ],
        ],
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/purchases', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'amount_total_mismatch');
});

it('handles private purchase attachments upload list download and delete', function (): void {
    $company = module3FiscalCompany('business-attachments');
    $purchaseId = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/purchases', module3PurchasePayload($company->external_business_id, ['idempotency_key' => 'att-1']))
        ->assertCreated()
        ->json('data.id');

    $file = UploadedFile::fake()->create('factura-proveedor.pdf', 12, 'application/pdf');

    $attachment = $this
        ->withToken('test-token')
        ->post("/api/fiscal/purchases/{$purchaseId}/attachments", [
            'business_id' => $company->external_business_id,
            'file' => $file,
        ])
        ->assertCreated()
        ->assertJsonPath('data.original_name', 'factura-proveedor.pdf')
        ->assertJsonPath('data.mime', 'application/pdf')
        ->assertJsonMissingPath('data.storage_key')
        ->json('data');

    $attachmentModel = FiscalPurchaseAttachment::query()->findOrFail($attachment['id']);

    Storage::disk('local')->assertExists($attachmentModel->storage_key);
    expect($attachmentModel->storage_key)->toStartWith('fiscal-purchase-attachments/')
        ->and($attachment['sha256'])->toHaveLength(64);

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/purchases/{$purchaseId}/attachments?business_id={$company->external_business_id}")
        ->assertOk()
        ->assertJsonPath('data.0.id', $attachment['id'])
        ->assertJsonMissingPath('data.0.storage_key');

    $this
        ->withToken('test-token')
        ->get("/api/fiscal/purchases/{$purchaseId}/attachments/{$attachment['id']}?business_id={$company->external_business_id}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this
        ->withToken('test-token')
        ->deleteJson("/api/fiscal/purchases/{$purchaseId}/attachments/{$attachment['id']}", [
            'business_id' => $company->external_business_id,
        ])
        ->assertNoContent();

    Storage::disk('local')->assertMissing($attachmentModel->storage_key);
});

it('deletes private attachment files when deleting a fiscal purchase', function (): void {
    $company = module3FiscalCompany('business-purchase-delete');
    $purchaseId = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/purchases', module3PurchasePayload($company->external_business_id, ['idempotency_key' => 'purchase-delete-1']))
        ->assertCreated()
        ->json('data.id');

    $attachmentId = $this
        ->withToken('test-token')
        ->post("/api/fiscal/purchases/{$purchaseId}/attachments", [
            'business_id' => $company->external_business_id,
            'file' => UploadedFile::fake()->create('factura-proveedor.pdf', 8, 'application/pdf'),
        ])
        ->assertCreated()
        ->json('data.id');

    $attachment = FiscalPurchaseAttachment::query()->findOrFail($attachmentId);
    Storage::disk('local')->assertExists($attachment->storage_key);

    $this
        ->withToken('test-token')
        ->deleteJson("/api/fiscal/purchases/{$purchaseId}", [
            'business_id' => $company->external_business_id,
        ])
        ->assertNoContent();

    Storage::disk('local')->assertMissing($attachment->storage_key);
    expect(FiscalPurchaseAttachment::query()->whereKey($attachmentId)->exists())->toBeFalse();
});

it('returns IVA position payable credit and zero using the IVA book service totals', function (): void {
    $payable = module3FiscalCompany('business-payable');
    module3AuthorizedDocument($payable, ['imp_iva' => 42, 'imp_total' => 242], [['iva_id' => 5, 'rate' => 21, 'base_imp' => 200, 'importe' => 42]]);
    module3Purchase($payable, ['imp_iva' => 21, 'imp_total' => 121], [['iva_id' => 5, 'rate' => 21, 'base_imp' => 100, 'importe' => 21]]);

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/iva/position?business_id={$payable->external_business_id}&date_from=2026-08-01&date_to=2026-08-31")
        ->assertOk()
        ->assertJsonPath('data.debit_vat', '42.00')
        ->assertJsonPath('data.credit_vat', '21.00')
        ->assertJsonPath('data.estimated_position', '21.00')
        ->assertJsonPath('data.result', 'payable')
        ->assertJsonPath('data.iva_by_aliquot.0.id', 5);

    $credit = module3FiscalCompany('business-credit');
    module3AuthorizedDocument($credit, ['imp_iva' => 10.5, 'imp_total' => 110.5], [['iva_id' => 4, 'rate' => 10.5, 'base_imp' => 100, 'importe' => 10.5]]);
    module3Purchase($credit, ['imp_iva' => 27, 'imp_total' => 127], [['iva_id' => 6, 'rate' => 27, 'base_imp' => 100, 'importe' => 27]]);

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/iva/position?business_id={$credit->external_business_id}&date_from=2026-08-01&date_to=2026-08-31")
        ->assertOk()
        ->assertJsonPath('data.estimated_position', '-16.50')
        ->assertJsonPath('data.result', 'credit');

    $zero = module3FiscalCompany('business-zero');
    module3AuthorizedDocument($zero, ['imp_iva' => 21, 'imp_total' => 121], [['iva_id' => 5, 'rate' => 21, 'base_imp' => 100, 'importe' => 21]]);
    module3Purchase($zero, ['imp_iva' => 21, 'imp_total' => 121], [['iva_id' => 5, 'rate' => 21, 'base_imp' => 100, 'importe' => 21]]);

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/iva/position?business_id={$zero->external_business_id}&date_from=2026-08-01&date_to=2026-08-31")
        ->assertOk()
        ->assertJsonPath('data.estimated_position', '0.00')
        ->assertJsonPath('data.result', 'zero');
});

it('prevents scoped cross-company access for documents purchases and attachments', function (): void {
    $first = module3FiscalCompany('business-first');
    $second = module3FiscalCompany('business-second', ['cuit' => '20987654321']);
    $document = module3AuthorizedDocument($first);
    $purchase = module3Purchase($first);
    $attachment = $purchase->attachments()->create([
        'original_name' => 'x.pdf',
        'mime' => 'application/pdf',
        'size' => 3,
        'storage_key' => 'fiscal-purchase-attachments/1/1/x.pdf',
        'sha256' => hash('sha256', 'pdf'),
        'uploaded_at' => now(),
    ]);
    Storage::disk('local')->put($attachment->storage_key, 'pdf');

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/documents/{$document->id}?business_id={$second->external_business_id}")
        ->assertForbidden()
        ->assertJsonPath('error_code', 'company_scope_mismatch');

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/purchases/{$purchase->id}?business_id={$second->external_business_id}")
        ->assertForbidden()
        ->assertJsonPath('error_code', 'company_scope_mismatch');

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/purchases/{$purchase->id}/attachments/{$attachment->id}?business_id={$second->external_business_id}")
        ->assertForbidden()
        ->assertJsonPath('error_code', 'company_scope_mismatch');
});

it('prevents cross-company CAEA report and can require company scope on id routes', function (): void {
    $first = module3FiscalCompany('business-caea-first');
    $second = module3FiscalCompany('business-caea-second', ['cuit' => '20987654321']);

    $documentId = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', module3FiscalPayload($first->external_business_id, [
            'authorization_type' => 'CAEA',
            'idempotency_key' => 'caea-scope-1',
            'caea' => [
                'code' => '12345678901234',
                'report_now' => false,
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.fiscal_status', 'pending_report')
        ->json('data.id');

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$documentId}/caea/report", [
            'business_id' => $second->external_business_id,
        ])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'company_scope_mismatch');

    config(['fiscal.security.require_company_scope_for_id_routes' => true]);

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$documentId}/caea/report")
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'company_scope_required');

    $this
        ->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$documentId}/caea/report", [
            'business_id' => $first->external_business_id,
        ])
        ->assertOk()
        ->assertJsonPath('data.fiscal_status', 'reported');
});

it('accepts appointment origin in documents and by-origin lookup', function (): void {
    $company = module3FiscalCompany('business-appointment');

    $documentId = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', module3FiscalPayload($company->external_business_id, [
            'idempotency_key' => 'appointment-1',
            'origin' => [
                'type' => 'appointment',
                'id' => 'turno-77',
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.origin.type', 'appointment')
        ->json('data.id');

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/documents/by-origin?business_id={$company->external_business_id}&origin_type=appointment&origin_id=turno-77")
        ->assertOk()
        ->assertJsonPath('data.0.id', $documentId)
        ->assertJsonPath('data.0.origin.type', 'appointment');
});

function module3FiscalCompany(string $businessId, array $overrides = []): FiscalCompany
{
    $cuit = $overrides['cuit'] ?? (string) (20100000000 + FiscalCompany::query()->count());

    $company = FiscalCompany::query()->create([
        'external_business_id' => $businessId,
        'cuit' => $cuit,
        'legal_name' => 'Paula Beauty Studio',
        'fiscal_condition' => 'responsable_inscripto',
        'environment' => 'testing',
        'default_point_of_sale' => 1,
        'default_voucher_type' => 1,
        'enabled' => true,
        ...$overrides,
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

function module3FiscalPayload(string $businessId, array $overrides = []): array
{
    $payload = [
        'business_id' => $businessId,
        'invoice_mode' => 'manual',
        'cbte_type' => 1,
        'document_type' => 'invoice_a',
        'concept' => 1,
        'voucher_date' => '2026-08-19',
        'origin' => [
            'type' => 'sale',
            'id' => 'sale-1',
        ],
        'customer' => [
            'name' => 'Cliente RI',
            'document_type' => 'CUIT',
            'document_number' => '30712345671',
            'iva_condition' => 'responsable_inscripto',
            'email' => 'cliente@example.test',
        ],
        'amounts' => [
            'imp_total' => 121,
            'imp_neto' => 100,
            'imp_iva' => 21,
            'imp_trib' => 0,
            'imp_op_ex' => 0,
            'imp_tot_conc' => 0,
            'iva_items' => [
                ['id' => 5, 'base_imp' => 100, 'importe' => 21],
            ],
        ],
        'currency' => 'PES',
        'currency_rate' => 1,
        'idempotency_key' => 'module3-doc-1',
    ];

    return array_replace_recursive($payload, $overrides);
}

function module3PurchasePayload(string $businessId, array $overrides = []): array
{
    $payload = [
        'business_id' => $businessId,
        'origin' => ['type' => 'purchase', 'id' => 'purchase-source-1'],
        'category' => 'insumos',
        'concept' => 'Productos profesionales',
        'voucher_date' => '2026-08-19',
        'accounting_date' => '2026-08-19',
        'cbte_type' => 1,
        'point_of_sale' => 2,
        'document_number' => 9001,
        'supplier' => [
            'cuit' => '30712345671',
            'name' => 'Proveedor Belleza SA',
            'iva_condition' => 'responsable_inscripto',
        ],
        'amounts' => [
            'imp_total' => 363.5,
            'imp_neto' => 300,
            'imp_iva' => 58.5,
            'imp_trib' => 5,
            'imp_op_ex' => 0,
            'imp_tot_conc' => 0,
            'iva_items' => [
                ['id' => 5, 'base_imp' => 100, 'importe' => 21],
                ['id' => 4, 'base_imp' => 100, 'importe' => 10.5],
                ['id' => 6, 'base_imp' => 100, 'importe' => 27],
            ],
            'trib_items' => [
                ['id' => 99, 'desc' => 'Percepcion IIBB', 'base_imp' => 300, 'alic' => 1.6667, 'importe' => 5],
            ],
        ],
        'payment_method' => 'transferencia',
        'payment_status' => 'paid',
        'due_date' => '2026-08-30',
    ];

    return array_replace_recursive($payload, $overrides);
}

function module3AuthorizedDocument(FiscalCompany $company, array $overrides = [], array $ivaItems = []): FiscalDocument
{
    $document = FiscalDocument::query()->create([
        'fiscal_company_id' => $company->id,
        'origin_type' => 'manual',
        'origin_id' => null,
        'document_type' => 'invoice_a',
        'point_of_sale' => 1,
        'voucher_type' => 1,
        'concept' => 1,
        'document_number' => 1,
        'voucher_date' => '2026-08-19',
        'customer_doc_type' => 80,
        'customer_doc_number' => '30712345671',
        'customer_name' => 'Cliente Test',
        'customer_iva_condition' => 'responsable_inscripto',
        'imp_total' => 121,
        'imp_neto' => 100,
        'imp_iva' => 21,
        'imp_trib' => 0,
        'imp_op_ex' => 0,
        'imp_tot_conc' => 0,
        'status' => 'authorized',
        'fiscal_status' => 'authorized',
        'authorization_type' => 'CAE',
        'authorization_code' => '12345678901234',
        'authorization_expires_at' => '2026-08-31',
        'cae' => '12345678901234',
        'cae_expires_at' => '2026-08-31',
        'idempotency_key' => 'direct-doc-'.uniqid(),
        'normalized_payload' => [
            'currency' => 'PES',
            'currency_rate' => '1.000000',
            'amounts' => ['trib_items' => []],
            'customer' => ['email' => 'cliente@example.test'],
        ],
        ...$overrides,
    ]);

    foreach ($ivaItems ?: [['iva_id' => 5, 'rate' => 21, 'base_imp' => 100, 'importe' => 21]] as $item) {
        $document->ivaItems()->create($item);
    }

    return $document;
}

function module3Purchase(FiscalCompany $company, array $overrides = [], array $ivaItems = []): FiscalPurchase
{
    $purchase = FiscalPurchase::query()->create([
        'fiscal_company_id' => $company->id,
        'origin_type' => 'manual',
        'voucher_date' => '2026-08-19',
        'voucher_type' => 1,
        'document_type' => 'invoice_a',
        'point_of_sale' => 2,
        'document_number' => random_int(1000, 9999),
        'supplier_cuit' => '30712345671',
        'supplier_name' => 'Proveedor Test',
        'supplier_iva_condition' => 'responsable_inscripto',
        'imp_total' => 121,
        'imp_neto' => 100,
        'imp_iva' => 21,
        'imp_trib' => 0,
        'imp_op_ex' => 0,
        'imp_tot_conc' => 0,
        'currency' => 'PES',
        'currency_rate' => 1,
        'payment_status' => 'pending',
        ...$overrides,
    ]);

    foreach ($ivaItems ?: [['iva_id' => 5, 'rate' => 21, 'base_imp' => 100, 'importe' => 21]] as $item) {
        $purchase->ivaItems()->create($item);
    }

    return $purchase;
}
