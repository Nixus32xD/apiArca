<?php

use App\Exceptions\Fiscal\FiscalException;
use App\Models\AccessTicket;
use App\Models\FiscalApiLog;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Models\FiscalSequenceReservation;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use App\Services\Fiscal\FiscalCompanyResolver;
use App\Services\Fiscal\FiscalInvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        /** @var array<int, array{fiscal_company_id: int, point_of_sale: int, voucher_type: int}> */
        public array $lastAuthorizedCalls = [];

        /** @var array<int, int> */
        public array $authorizedCompanyIds = [];

        public ?FiscalException $authorizeException = null;

        public ?Throwable $authorizeThrowable = null;

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
            $this->authorizedCompanyIds[] = $company->id;

            if ($this->authorizeException) {
                throw $this->authorizeException;
            }

            if ($this->authorizeThrowable) {
                throw $this->authorizeThrowable;
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
            $this->lastAuthorizedCalls[] = [
                'fiscal_company_id' => $company->id,
                'point_of_sale' => $pointOfSale,
                'voucher_type' => $voucherType,
            ];

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

it('resolves monotributista issuer and consumer final receiver as factura c', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'monotributo',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload($company->external_business_id, idempotencyKey: 'auto-mono-cf'))
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 11)
        ->assertJsonPath('data.document_type', 'invoice_c');

    expect(FiscalDocument::query()->firstOrFail()->normalized_payload['customer']['doc_type'])->toBe(99);
});

it('resolves monotributista issuer and RI receiver as factura c', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'monotributo',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload(
            $company->external_business_id,
            fiscalReceiver('responsable_inscripto'),
            'auto-mono-ri'
        ))
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 11)
        ->assertJsonPath('data.document_type', 'invoice_c');
});

it('resolves exento issuer and consumer final receiver as factura c', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'exento',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload($company->external_business_id, idempotencyKey: 'auto-exento-cf'))
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 11)
        ->assertJsonPath('data.document_type', 'invoice_c');
});

it('resolves RI issuer and consumer final receiver as factura b', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload($company->external_business_id, idempotencyKey: 'auto-ri-cf'))
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 6)
        ->assertJsonPath('data.document_type', 'invoice_b');
});

it('resolves RI issuer and monotributista receiver as factura b', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload(
            $company->external_business_id,
            fiscalReceiver('monotributo'),
            'auto-ri-mono'
        ))
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 6)
        ->assertJsonPath('data.document_type', 'invoice_b');
});

it('resolves RI issuer and exento receiver as factura b', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload(
            $company->external_business_id,
            fiscalReceiver('exento'),
            'auto-ri-exento'
        ))
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 6)
        ->assertJsonPath('data.document_type', 'invoice_b');
});

it('resolves RI issuer and RI receiver as factura a', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload(
            $company->external_business_id,
            fiscalReceiver('responsable_inscripto'),
            'auto-ri-ri'
        ))
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 1)
        ->assertJsonPath('data.document_type', 'invoice_a');
});

it('exposes normalized fiscal amounts and IVA aliquots through the API', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
        'default_voucher_type' => null,
    ]);

    $payload = fiscalAutoPayload(
        $company->external_business_id,
        fiscalReceiver('responsable_inscripto'),
        'auto-ri-multi-iva',
        [
            'amounts' => [
                'imp_total' => 176.25,
                'imp_neto' => 150,
                'imp_iva' => 26.25,
                'imp_trib' => 0,
                'imp_op_ex' => 0,
                'imp_tot_conc' => 0,
                'iva_items' => [
                    ['id' => 5, 'base_imp' => 100, 'importe' => 21],
                    ['id' => 4, 'base_imp' => 50, 'importe' => 5.25],
                ],
            ],
        ],
    );

    $response = $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', $payload)
        ->assertCreated()
        ->assertJsonPath('data.amounts.imp_total', '176.25')
        ->assertJsonPath('data.amounts.imp_neto', '150.00')
        ->assertJsonPath('data.amounts.imp_iva', '26.25');

    expect(collect($response->json('data.amounts.iva_items'))->pluck('id')->sort()->values()->all())
        ->toBe([4, 5]);

    $documentId = $response->json('data.id');

    $showResponse = $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/documents/{$documentId}")
        ->assertOk()
        ->assertJsonPath('data.customer.iva_condition', 'responsable_inscripto');

    expect(collect($showResponse->json('data.amounts.iva_items'))->pluck('rate')->sort()->values()->all())
        ->toBe(['10.50', '21.00']);
});

it('issues a credit note with the corresponding ARCA voucher type and associated voucher', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload(
            $company->external_business_id,
            fiscalReceiver('responsable_inscripto'),
            'credit-note-a',
            [
                'document_kind' => 'credit_note',
                'associated_vouchers' => [
                    ['type' => 1, 'point_of_sale' => 1, 'number' => 10],
                ],
            ],
        ))
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 3)
        ->assertJsonPath('data.document_type', 'credit_note_a')
        ->assertJsonPath('data.document_kind', 'credit_note');
});

it('rejects credit and debit notes without an associated voucher', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload(
            $company->external_business_id,
            fiscalReceiver('responsable_inscripto'),
            'credit-note-without-associated',
            ['document_kind' => 'credit_note'],
        ))
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'associated_voucher_required');
});

it('rejects factura a without receiver CUIT', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
        'default_voucher_type' => null,
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalAutoPayload(
            $company->external_business_id,
            [
                'name' => 'Empresa sin CUIT',
                'document_type' => 'DNI',
                'document_number' => '12345678',
                'iva_condition' => 'responsable_inscripto',
            ],
            'auto-a-without-cuit',
            ['cbte_type' => 1, 'invoice_mode' => 'manual']
        ))
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'receiver_cuit_required');
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

it('blocks a new emission when another numbered document in the same sequence is uncertain', function (): void {
    $company = fiscalCompanyWithTicket();
    fiscalUnresolvedDocument($company, ['idempotency_key' => 'gap-a']);

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalPayload($company->external_business_id, idempotencyKey: 'gap-b'))
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'fiscal_sequence_requires_reconcile');

    expect(FiscalDocument::query()->count())->toBe(1)
        ->and($this->wsfe->authorizeCalls)->toBe(0);
});

it('does not let a retry hide another unresolved document in the same sequence', function (): void {
    $company = fiscalCompanyWithTicket();
    $first = fiscalUnresolvedDocument($company, ['idempotency_key' => 'gap-a', 'document_number' => 11]);
    $retryTarget = fiscalUnresolvedDocument($company, [
        'idempotency_key' => 'retry-b',
        'document_number' => 12,
        'status' => 'error',
        'fiscal_status' => 'failed',
        'error_code' => 'arca_voucher_not_found',
    ]);

    $this->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$retryTarget->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'fiscal_sequence_requires_reconcile')
        ->assertJsonPath('context.document_id', $first->id);

    expect($this->wsfe->consultCalls)->toBe(0)
        ->and($this->wsfe->authorizeCalls)->toBe(0);
});

it('allows reconciliation of its uncertain target even with unresolved sequence records', function (): void {
    $company = fiscalCompanyWithTicket();
    $target = fiscalUnresolvedDocument($company, ['idempotency_key' => 'gap-a']);
    fiscalUnresolvedDocument($company, ['idempotency_key' => 'gap-b', 'document_number' => 12]);

    $this->withToken('test-token')
        ->postJson("/api/fiscal/documents/{$target->id}/reconcile")
        ->assertOk()
        ->assertJsonPath('data.status', 'authorized');

    expect($this->wsfe->consultCalls)->toBe(1);
});

it('isolates unresolved sequence gaps by point of sale voucher type and fiscal company', function (): void {
    $company = fiscalCompanyWithTicket();
    $otherCompany = fiscalCompanyWithTicket(['external_business_id' => 'business-2', 'cuit' => '20333444559']);
    fiscalUnresolvedDocument($company, ['idempotency_key' => 'pv1-invoice-c']);

    $pv2 = fiscalPayload($company->external_business_id, idempotencyKey: 'pv2-invoice-c');
    $pv2['point_of_sale'] = 2;
    $this->withToken('test-token')->postJson('/api/fiscal/documents', $pv2)
        ->assertCreated()
        ->assertJsonPath('data.point_of_sale', 2);

    $creditNote = fiscalPayload($company->external_business_id, idempotencyKey: 'pv1-credit-c');
    $creditNote['cbte_type'] = 13;
    $creditNote['document_type'] = 'credit_note_c';
    $creditNote['associated_vouchers'] = [['type' => 11, 'point_of_sale' => 1, 'number' => 10]];
    $this->withToken('test-token')->postJson('/api/fiscal/documents', $creditNote)
        ->assertCreated()
        ->assertJsonPath('data.cbte_type', 13);

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalPayload($otherCompany->external_business_id, idempotencyKey: 'other-company'))
        ->assertCreated()
        ->assertJsonPath('data.company.id', $otherCompany->id);
});

it('replays an uncertain idempotency key without another authorization', function (): void {
    $company = fiscalCompanyWithTicket();
    $existing = fiscalUnresolvedDocument($company, ['idempotency_key' => 'uncertain-idempotency']);

    $response = $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalPayload($company->external_business_id, idempotencyKey: 'uncertain-idempotency'));

    $response->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true)
        ->assertJsonPath('data.id', $existing->id);

    expect(FiscalDocument::query()->count())->toBe(1)
        ->and($this->wsfe->authorizeCalls)->toBe(0);
});

it('enforces idempotency at the database boundary for competing persistence attempts', function (): void {
    $company = fiscalCompanyWithTicket();
    fiscalUnresolvedDocument($company, ['idempotency_key' => 'database-idempotency']);

    expect(fn () => fiscalUnresolvedDocument($company, ['idempotency_key' => 'database-idempotency']))
        ->toThrow(QueryException::class);

    expect(FiscalDocument::query()->where('fiscal_company_id', $company->id)->where('idempotency_key', 'database-idempotency')->count())
        ->toBe(1);
});

it('allows two MySQL processes to issue one idempotent fiscal operation only once', function (): void {
    if (config('database.default') !== 'mysql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires MySQL and pcntl; it runs in the CI concurrency environment.');
    }

    $company = fiscalCompanyWithTicket();
    $payload = fiscalPayload($company->external_business_id, 'forked-idempotency-key');
    $resultFiles = [tempnam(sys_get_temp_dir(), 'fiscal-idem-'), tempnam(sys_get_temp_dir(), 'fiscal-idem-')];
    $pids = [];

    try {
        foreach ($resultFiles as $resultFile) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not fork fiscal idempotency test process.');
            }

            if ($pid === 0) {
                DB::disconnect();

                try {
                    app(FiscalInvoiceService::class)->issue($payload);
                    file_put_contents($resultFile, 'ok');
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($resultFile, 'error:'.$exception::class);
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        DB::disconnect();
        expect(array_map('file_get_contents', $resultFiles))->toBe(['ok', 'ok'])
            ->and(FiscalDocument::query()->where('fiscal_company_id', $company->id)->where('idempotency_key', 'forked-idempotency-key')->count())->toBe(1)
            ->and(FiscalDocument::query()->where('fiscal_company_id', $company->id)->sole()->attempts()->where('operation', 'FECAESolicitar')->count())->toBe(1);
    } finally {
        foreach ($resultFiles as $resultFile) {
            if (is_string($resultFile) && is_file($resultFile)) {
                unlink($resultFile);
            }
        }
    }
});

it('forbids reusing a reserved number inside one fiscal sequence', function (): void {
    $company = fiscalCompanyWithTicket();
    $first = fiscalUnresolvedDocument($company, ['idempotency_key' => 'reservation-a']);
    $second = fiscalUnresolvedDocument($company, ['idempotency_key' => 'reservation-b', 'document_number' => 12]);

    FiscalSequenceReservation::query()->create([
        'fiscal_company_id' => $company->id,
        'fiscal_document_id' => $first->id,
        'point_of_sale' => 1,
        'voucher_type' => 11,
        'document_number' => 11,
    ]);

    expect(fn () => FiscalSequenceReservation::query()->create([
        'fiscal_company_id' => $company->id,
        'fiscal_document_id' => $second->id,
        'point_of_sale' => 1,
        'voucher_type' => 11,
        'document_number' => 11,
    ]))->toThrow(QueryException::class);
});

it('protects every document id route from an explicitly mismatched fiscal company scope', function (string $operation): void {
    $first = fiscalCompanyWithTicket();
    $second = fiscalCompanyWithTicket(['external_business_id' => 'scope-second', 'cuit' => '20333444559']);
    $document = fiscalUnresolvedDocument($first);
    $url = "/api/fiscal/documents/{$document->id}";

    $response = match ($operation) {
        'show' => $this->withToken('test-token')->getJson("{$url}?external_fiscal_id={$second->external_business_id}"),
        'pdf' => $this->withToken('test-token')->get("{$url}/pdf?external_fiscal_id={$second->external_business_id}"),
        'qr' => $this->withToken('test-token')->getJson("{$url}/qr?external_fiscal_id={$second->external_business_id}"),
        default => $this->withToken('test-token')->postJson("{$url}/{$operation}", ['external_fiscal_id' => $second->external_business_id]),
    };

    $response->assertForbidden()->assertJsonPath('error_code', 'company_scope_mismatch');
})->with(['show', 'pdf', 'qr', 'email', 'retry', 'reconcile']);

it('does not allow a scoped API client to access another fiscal identity by document id', function (): void {
    $allowed = fiscalCompanyWithTicket(['external_business_id' => 'scoped-allowed']);
    $denied = fiscalCompanyWithTicket(['external_business_id' => 'scoped-denied', 'cuit' => '20333444559']);
    $document = fiscalUnresolvedDocument($denied);
    config([
        'fiscal.api_tokens' => [],
        'fiscal.api_clients' => [[
            'id' => 'scoped-client',
            'token' => 'scoped-token',
            'external_fiscal_ids' => [$allowed->external_business_id],
        ]],
    ]);

    $this->withToken('scoped-token')
        ->getJson("/api/fiscal/documents/{$document->id}")
        ->assertForbidden()
        ->assertJsonPath('error_code', 'fiscal_company_forbidden');
});

it('allows the same CUIT in separate fiscal environments', function (): void {
    $testing = fiscalCompanyWithTicket(['external_business_id' => 'same-cuit-testing']);
    $production = FiscalCompany::query()->create([
        'external_business_id' => 'same-cuit-production',
        'cuit' => $testing->cuit,
        'legal_name' => 'Empresa Demo SA',
        'fiscal_condition' => 'monotributo',
        'environment' => 'production',
        'default_point_of_sale' => 1,
        'default_voucher_type' => 11,
        'enabled' => true,
    ]);

    expect($production->id)->not->toBe($testing->id)
        ->and($production->cuit)->toBe($testing->cuit)
        ->and($production->environment)->toBe('production');
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

it('marks ambiguous authorization failures as uncertain after FECAESolicitar starts', function (Throwable $exception): void {
    $company = fiscalCompanyWithTicket();

    if ($exception instanceof FiscalException) {
        $this->wsfe->authorizeException = $exception;
    } else {
        $this->wsfe->authorizeThrowable = $exception;
    }

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', fiscalPayload($company->external_business_id))
        ->assertCreated()
        ->assertJsonPath('data.status', 'uncertain')
        ->assertJsonPath('data.fiscal_status', 'uncertain');
})->with([
    'connection timeout' => new FiscalException('timeout', 504, 'arca_timeout'),
    'http 502' => new FiscalException('bad gateway', 502, 'arca_http_error', ['status_code' => 502]),
    'soap fault' => new FiscalException('fault', 502, 'soap_fault'),
    'invalid xml' => new FiscalException('invalid xml', 502, 'invalid_xml'),
    'missing wsfe response node' => new FiscalException('missing node', 502, 'soap_response_missing_node'),
    'invalid wsfe response' => new FiscalException('invalid response', 502, 'wsfe_invalid_response'),
    'unexpected post-send failure' => new RuntimeException('post-send failure'),
]);

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

it('stores supplier purchases and returns IVA compras totals', function (): void {
    $company = fiscalCompanyWithTicket([
        'fiscal_condition' => 'responsable_inscripto',
    ]);

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/purchases', [
            'business_id' => $company->external_business_id,
            'voucher_date' => '2026-04-10',
            'cbte_type' => 1,
            'point_of_sale' => 2,
            'document_number' => 123,
            'supplier' => [
                'cuit' => '30712345671',
                'name' => 'Proveedor SA',
                'iva_condition' => 'responsable_inscripto',
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
            'payment_method' => 'transferencia',
        ])
        ->assertCreated()
        ->assertJsonPath('data.supplier.cuit', '30712345671')
        ->assertJsonPath('data.payment.method', 'bank_transfer')
        ->assertJsonPath('data.amounts.iva_items.0.rate', '21.00');

    $this
        ->withToken('test-token')
        ->getJson("/api/fiscal/purchases/iva-book?business_id={$company->external_business_id}&date_from=2026-04-01&date_to=2026-04-30")
        ->assertOk()
        ->assertJsonPath('data.totals.imp_total', '121.00')
        ->assertJsonPath('data.totals.imp_iva', '21.00')
        ->assertJsonPath('data.totals.iva_by_aliquot.0.id', 5);
});

it('renders the fiscal admin dashboard in testing', function (): void {
    fiscalCompanyWithTicket();

    $this
        ->get('/api/admin/')
        ->assertOk()
        ->assertSee('Fiscal admin')
        ->assertSee('IVA Ventas')
        ->assertSee('IVA Compras');
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

it('rejects a second external fiscal id for the same CUIT and environment', function (): void {
    fiscalCompanyWithTicket();

    $this
        ->withToken('test-token')
        ->postJson('/api/fiscal/companies', [
            'external_fiscal_id' => 'another-fiscal-id',
            'cuit' => '20123456789',
            'legal_name' => 'Empresa Demo SA',
            'fiscal_condition' => 'monotributo',
            'environment' => 'testing',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'fiscal_company_identity_exists')
        ->assertJsonPath('context.external_fiscal_id', 'business-1')
        ->assertJsonPath('context.business_id', 'business-1')
        ->assertJsonPath('context.external_business_id', 'business-1');
});

it('keeps CUIT and environment immutable after fiscal history exists', function (string $history): void {
    $company = fiscalCompanyForCredentialOnboarding('immutable-'.$history);

    match ($history) {
        'document' => $company->documents()->create([
            'point_of_sale' => 5,
            'voucher_type' => 11,
            'idempotency_key' => 'immutable-document',
        ]),
        'credential' => $company->credentials()->create([
            'certificate' => 'certificate',
            'private_key' => 'private-key',
            'active' => true,
            'status' => 'active',
        ]),
        'access_ticket' => $company->accessTickets()->create([
            'service' => 'wsfe',
            'token' => 'token',
            'sign' => 'sign',
            'generation_time' => now()->subMinute(),
            'expiration_time' => now()->addHour(),
        ]),
    };

    $basePayload = [
        'external_fiscal_id' => $company->external_business_id,
        'legal_name' => $company->legal_name,
        'fiscal_condition' => $company->fiscal_condition,
        'default_point_of_sale' => 5,
        'default_voucher_type' => 11,
        'enabled' => true,
    ];

    $this->withToken('test-token')
        ->putJson("/api/fiscal/companies/{$company->external_business_id}", [
            ...$basePayload,
            'cuit' => '20987654321',
            'environment' => 'testing',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'fiscal_identity_immutable');

    $this->withToken('test-token')
        ->putJson("/api/fiscal/companies/{$company->external_business_id}", [
            ...$basePayload,
            'cuit' => $company->cuit,
            'environment' => 'production',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'fiscal_identity_immutable');
})->with(['document', 'credential', 'access_ticket']);

it('never resolves a numeric external fiscal identifier as an internal primary key', function (): void {
    fiscalCompanyWithTicket(['external_business_id' => 'erp-acme', 'cuit' => '20123456780']);

    expect(fn () => app(FiscalCompanyResolver::class)->resolveExternalIdentifier('1'))
        ->toThrow(FiscalException::class, 'Fiscal company was not found.');
});

it('issues for a generic consumer without ComerStock identifiers', function (): void {
    $company = fiscalCompanyWithTicket(['external_business_id' => 'erp-acme', 'cuit' => '20123456780']);
    $payload = fiscalPayload($company->external_business_id);
    unset($payload['business_id'], $payload['sale_id']);
    $payload['external_fiscal_id'] = 'erp-acme';
    $payload['origin'] = ['type' => 'transaction', 'id' => 'TX-1000'];
    $payload['metadata'] = ['source' => 'erp_x', 'store' => 'NY-04', 'transaction' => 'TX-1000'];

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'authorized')
        ->assertJsonPath('data.number', 11);

    expect(FiscalDocument::query()->firstOrFail()->origin_type)->toBe('transaction');
});

it('uses one fiscal identity with independent sequences for explicit points of sale', function (): void {
    config(['fiscal-sequence.store' => 'array']);
    $company = fiscalCompanyWithTicket([
        'external_business_id' => 'fiscal-a',
        'cuit' => '20123456780',
        'default_point_of_sale' => 1,
    ]);

    $pv5 = fiscalPayload($company->external_business_id);
    unset($pv5['business_id'], $pv5['sale_id']);
    $pv5['external_fiscal_id'] = $company->external_business_id;
    $pv5['point_of_sale'] = 5;
    $pv5['idempotency_key'] = 'fiscal-a-pv-5';

    $this->withToken('test-token')
        ->postJson('/api/fiscal/documents', $pv5)
        ->assertCreated()
        ->assertJsonPath('data.external_fiscal_id', 'fiscal-a')
        ->assertJsonPath('data.company.external_fiscal_id', 'fiscal-a')
        ->assertJsonPath('data.company.cuit', '20123456780')
        ->assertJsonPath('data.company.legal_name', 'Empresa Demo SA')
        ->assertJsonPath('data.company.fiscal_condition', 'monotributo')
        ->assertJsonPath('data.company.environment', 'testing')
        ->assertJsonPath('data.point_of_sale', 5)
        ->assertJsonPath('data.cbte_type', 11)
        ->assertJsonPath('data.number', 11)
        ->assertJsonPath('data.authorization_type', 'CAE')
        ->assertJsonPath('data.authorization_code', '12345678901234');

    $pv5Lock = Cache::store('array')->lock("fiscal:sequence:{$company->id}:testing:5:11", 60);
    expect($pv5Lock->get())->toBeTrue();

    try {
        $pv8 = [...$pv5, 'point_of_sale' => 8, 'idempotency_key' => 'fiscal-a-pv-8'];

        $this->withToken('test-token')
            ->postJson('/api/fiscal/documents', $pv8)
            ->assertCreated()
            ->assertJsonPath('data.point_of_sale', 8)
            ->assertJsonPath('data.number', 11);
    } finally {
        $pv5Lock->release();
    }

    expect(FiscalDocument::query()->where('fiscal_company_id', $company->id)->pluck('point_of_sale')->sort()->values()->all())
        ->toBe([5, 8])
        ->and(FiscalSequenceReservation::query()->where('fiscal_company_id', $company->id)->pluck('document_number')->all())
        ->toBe([11, 11])
        ->and($this->wsfe->authorizedCompanyIds)->toBe([$company->id, $company->id])
        ->and($this->wsfe->lastAuthorizedCalls)->toContain([
            'fiscal_company_id' => $company->id,
            'point_of_sale' => 5,
            'voucher_type' => 11,
        ])
        ->toContain([
            'fiscal_company_id' => $company->id,
            'point_of_sale' => 8,
            'voucher_type' => 11,
        ]);
});

it('isolates same POS sequences by fiscal identity for a client authorized for two CUITs', function (): void {
    config(['fiscal-sequence.store' => 'array']);
    $first = fiscalCompanyWithTicket(['external_business_id' => 'fiscal-a', 'cuit' => '20123456780']);
    $second = fiscalCompanyWithTicket(['external_business_id' => 'fiscal-b', 'cuit' => '20333444559']);
    $forbidden = fiscalCompanyWithTicket(['external_business_id' => 'fiscal-c', 'cuit' => '20444555660']);
    config([
        'fiscal.api_tokens' => [],
        'fiscal.api_clients' => [[
            'id' => 'multi-fiscal-saas',
            'token' => 'multi-fiscal-token',
            'external_fiscal_ids' => ['fiscal-a', 'fiscal-b'],
        ]],
    ]);

    $firstLock = Cache::store('array')->lock("fiscal:sequence:{$first->id}:testing:5:11", 60);
    expect($firstLock->get())->toBeTrue();

    try {
        $secondPayload = fiscalPayload($second->external_business_id);
        unset($secondPayload['business_id'], $secondPayload['sale_id']);
        $secondPayload['external_fiscal_id'] = $second->external_business_id;
        $secondPayload['point_of_sale'] = 5;
        $secondPayload['idempotency_key'] = 'fiscal-b-pv-5';

        $this->withToken('multi-fiscal-token')
            ->postJson('/api/fiscal/documents', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.external_fiscal_id', 'fiscal-b')
            ->assertJsonPath('data.point_of_sale', 5)
            ->assertJsonPath('data.number', 11);
    } finally {
        $firstLock->release();
    }

    $firstPayload = fiscalPayload($first->external_business_id);
    unset($firstPayload['business_id'], $firstPayload['sale_id']);
    $firstPayload['external_fiscal_id'] = $first->external_business_id;
    $firstPayload['point_of_sale'] = 5;
    $firstPayload['idempotency_key'] = 'fiscal-a-pv-5';

    $this->withToken('multi-fiscal-token')
        ->postJson('/api/fiscal/documents', $firstPayload)
        ->assertCreated()
        ->assertJsonPath('data.external_fiscal_id', 'fiscal-a')
        ->assertJsonPath('data.point_of_sale', 5)
        ->assertJsonPath('data.number', 11);

    $forbiddenPayload = fiscalPayload($forbidden->external_business_id);
    $forbiddenPayload['external_fiscal_id'] = $forbidden->external_business_id;

    $this->withToken('multi-fiscal-token')
        ->postJson('/api/fiscal/documents', $forbiddenPayload)
        ->assertForbidden()
        ->assertJsonPath('error_code', 'fiscal_company_forbidden');

    expect(FiscalSequenceReservation::query()->where('document_number', 11)->count())->toBe(2)
        ->and(FiscalDocument::query()->where('document_number', 11)->pluck('fiscal_company_id')->sort()->values()->all())
        ->toBe([$first->id, $second->id])
        ->and($this->wsfe->lastAuthorizedCalls)->toContain([
            'fiscal_company_id' => $first->id,
            'point_of_sale' => 5,
            'voucher_type' => 11,
        ])
        ->toContain([
            'fiscal_company_id' => $second->id,
            'point_of_sale' => 5,
            'voucher_type' => 11,
        ]);
});

it('fails closed when the fiscal sequence lock is unavailable', function (): void {
    config(['fiscal-sequence.store' => 'array', 'fiscal-sequence.wait_seconds' => 0]);
    $company = fiscalCompanyWithTicket();
    $lock = Cache::store('array')->lock("fiscal:sequence:{$company->id}:{$company->environment}:1:11", 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->withToken('test-token')
            ->postJson('/api/fiscal/documents', fiscalPayload($company->external_business_id))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'fiscal_sequence_busy');

        expect(FiscalDocument::query()->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('authorizes a named API client only for its configured fiscal identities', function (): void {
    $allowed = fiscalCompanyWithTicket(['external_business_id' => 'erp-allowed', 'cuit' => '20123456780']);
    $denied = fiscalCompanyWithTicket(['external_business_id' => 'erp-denied', 'cuit' => '20333444559']);
    config([
        'fiscal.api_tokens' => [],
        'fiscal.api_clients' => [[
            'id' => 'erp-x',
            'token' => 'erp-token',
            'external_fiscal_ids' => [$allowed->external_business_id],
        ]],
    ]);

    $payload = fiscalPayload($denied->external_business_id);
    $payload['external_fiscal_id'] = $denied->external_business_id;

    $this->withToken('erp-token')
        ->postJson('/api/fiscal/documents', $payload)
        ->assertForbidden()
        ->assertJsonPath('error_code', 'fiscal_company_forbidden');
});

function fiscalCompanyWithTicket(array $overrides = []): FiscalCompany
{
    $company = FiscalCompany::query()->create([
        'external_business_id' => 'business-1',
        'cuit' => '20123456789',
        'legal_name' => 'Empresa Demo SA',
        'fiscal_condition' => 'monotributo',
        'environment' => 'testing',
        'default_point_of_sale' => 1,
        'default_voucher_type' => 11,
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

function fiscalCompanyForCredentialOnboarding(string $businessId = 'business-csr'): FiscalCompany
{
    return FiscalCompany::query()->create([
        'external_business_id' => $businessId,
        'cuit' => '20123456789',
        'legal_name' => 'Empresa Demo SA',
        'fiscal_condition' => 'monotributo',
        'environment' => 'testing',
        'default_point_of_sale' => 1,
        'default_voucher_type' => 11,
        'enabled' => true,
    ]);
}

function fiscalUnresolvedDocument(FiscalCompany $company, array $overrides = []): FiscalDocument
{
    return FiscalDocument::query()->create([
        'fiscal_company_id' => $company->id,
        'point_of_sale' => 1,
        'voucher_type' => 11,
        'document_number' => 11,
        'status' => 'uncertain',
        'fiscal_status' => 'uncertain',
        'authorization_type' => 'CAE',
        'idempotency_key' => 'unresolved-'.uniqid(),
        ...$overrides,
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
function fiscalPayload(string $businessId, string $idempotencyKey = 'idem-100'): array
{
    return [
        'business_id' => $businessId,
        'sale_id' => 'sale-100',
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
        'metadata' => [
            'source' => 'tests',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function fiscalAutoPayload(
    string $businessId,
    array $customer = [],
    string $idempotencyKey = 'idem-auto',
    array $extra = [],
): array {
    return [
        ...fiscalPayload($businessId),
        'invoice_mode' => 'auto',
        'origin' => [
            'type' => 'sale',
            'id' => 'sale-auto',
        ],
        'idempotency_key' => $idempotencyKey,
        'customer' => $customer,
        ...$extra,
    ];
}

/**
 * @return array<string, mixed>
 */
function fiscalReceiver(string $ivaCondition): array
{
    return [
        'name' => 'Cliente Fiscal',
        'document_type' => 'CUIT',
        'document_number' => '30712345671',
        'iva_condition' => $ivaCondition,
        'address' => 'Av. Siempre Viva 123',
    ];
}
