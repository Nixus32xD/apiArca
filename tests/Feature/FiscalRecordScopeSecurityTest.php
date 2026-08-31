<?php

use App\Models\FiscalCompany;
use App\Models\FiscalDocument;

beforeEach(function (): void {
    if (config('database.default') === 'sqlite' && ! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('The pdo_sqlite extension is required to run fiscal feature tests with the default phpunit database.');
    }

    $this->artisan('migrate:fresh');
    config([
        'fiscal.api_tokens' => ['test-token'],
        'fiscal.security.require_company_scope_for_id_routes' => true,
    ]);
});

it('requires company scope for fiscal document id routes when production hardening is enabled', function (): void {
    $company = scopeFiscalCompany('scope-business-a', '20123456789');
    $document = scopeFiscalDocument($company, 'scope-idem-a');

    $this->withToken('test-token')
        ->getJson("/api/fiscal/documents/{$document->id}")
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'company_scope_required');

    $this->withToken('test-token')
        ->getJson("/api/fiscal/documents/{$document->id}?business_id={$company->external_business_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $document->id);
});

it('rejects a fiscal document id when it is scoped to another company', function (): void {
    $companyA = scopeFiscalCompany('scope-business-a', '20123456789');
    $companyB = scopeFiscalCompany('scope-business-b', '20333444559');
    $document = scopeFiscalDocument($companyA, 'scope-idem-a');

    $this->withToken('test-token')
        ->getJson("/api/fiscal/documents/{$document->id}?business_id={$companyB->external_business_id}")
        ->assertForbidden()
        ->assertJsonPath('error_code', 'company_scope_mismatch');
});

function scopeFiscalCompany(string $businessId, string $cuit): FiscalCompany
{
    return FiscalCompany::query()->create([
        'external_business_id' => $businessId,
        'cuit' => $cuit,
        'legal_name' => 'Empresa '.$businessId,
        'fiscal_condition' => 'monotributo',
        'environment' => 'testing',
        'default_point_of_sale' => 1,
        'default_voucher_type' => 11,
        'enabled' => true,
    ]);
}

function scopeFiscalDocument(FiscalCompany $company, string $idempotencyKey): FiscalDocument
{
    return FiscalDocument::query()->create([
        'fiscal_company_id' => $company->id,
        'origin_type' => 'sale',
        'origin_id' => '100',
        'document_type' => 'invoice_c',
        'point_of_sale' => 1,
        'voucher_type' => 11,
        'concept' => 1,
        'document_number' => 10,
        'status' => 'authorized',
        'cae' => '12345678901234',
        'cae_expires_at' => '2026-09-10',
        'idempotency_key' => $idempotencyKey,
        'normalized_payload' => [
            'amounts' => [
                'imp_total' => '121.00',
                'imp_neto' => '121.00',
                'imp_iva' => '0.00',
                'iva_items' => [],
            ],
            'customer' => [],
        ],
    ]);
}
