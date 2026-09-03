<?php

use App\Models\FiscalCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
});

function fiscalNumberUniquenessCompany(): FiscalCompany
{
    return FiscalCompany::query()->create([
        'external_business_id' => 'number-unique-company',
        'cuit' => '20123456786',
        'legal_name' => 'Empresa única',
        'fiscal_condition' => 'monotributo',
        'environment' => 'testing',
    ]);
}

function insertFiscalNumberDocument(int $companyId, string $key): void
{
    DB::table('fiscal_documents')->insert([
        'fiscal_company_id' => $companyId,
        'point_of_sale' => 1,
        'voucher_type' => 11,
        'concept' => 1,
        'document_number' => 1,
        'status' => 'uncertain',
        'idempotency_key' => $key,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the final fiscal documents unique protects a numbered uncertain document too', function (): void {
    $company = fiscalNumberUniquenessCompany();
    insertFiscalNumberDocument($company->id, 'number-unique-a');

    expect(fn () => insertFiscalNumberDocument($company->id, 'number-unique-b'))
        ->toThrow(QueryException::class);
});

test('the unique migration stops without changing historical duplicate documents', function (): void {
    $migration = require database_path('migrations/2026_09_03_000002_restore_fiscal_documents_number_unique.php');
    $migration->down();
    $company = fiscalNumberUniquenessCompany();
    insertFiscalNumberDocument($company->id, 'number-preflight-a');
    insertFiscalNumberDocument($company->id, 'number-preflight-b');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class)
        ->and(DB::table('fiscal_documents')->count())->toBe(2);
});
