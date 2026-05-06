<?php

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Services\Fiscal\FiscalVoucherResolver;

it('resolves voucher type in auto mode from issuer and receiver fiscal conditions', function (
    string $issuerCondition,
    array $receiver,
    int $expectedVoucherType,
): void {
    $resolution = fiscalVoucherResolver()->resolve(
        fiscalVoucherCompany($issuerCondition),
        [
            'invoice_mode' => 'auto',
            'customer' => $receiver,
        ],
    );

    expect($resolution['voucher_type'])->toBe($expectedVoucherType);
})->with([
    'monotributo to consumidor final' => ['monotributo', [], 11],
    'monotributo to responsable inscripto' => ['monotributo', fiscalVoucherReceiver('responsable_inscripto'), 11],
    'exento to consumidor final' => ['exento', [], 11],
    'responsable inscripto to consumidor final' => ['responsable_inscripto', [], 6],
    'responsable inscripto to monotributo' => ['responsable_inscripto', fiscalVoucherReceiver('monotributo'), 6],
    'responsable inscripto to exento' => ['responsable_inscripto', fiscalVoucherReceiver('exento'), 6],
    'responsable inscripto to responsable inscripto' => ['responsable_inscripto', fiscalVoucherReceiver('responsable_inscripto'), 1],
]);

it('rejects factura a without receiver cuit', function (): void {
    fiscalVoucherResolver()->resolve(
        fiscalVoucherCompany('responsable_inscripto'),
        [
            'invoice_mode' => 'manual',
            'cbte_type' => 1,
            'customer' => [
                'name' => 'Cliente RI',
                'document_type' => 'DNI',
                'document_number' => '12345678',
                'iva_condition' => 'responsable_inscripto',
            ],
        ],
    );
})->throws(FiscalException::class, 'Receiver CUIT is required for the selected IVA condition.');

function fiscalVoucherResolver(): FiscalVoucherResolver
{
    return new FiscalVoucherResolver;
}

function fiscalVoucherCompany(string $condition): FiscalCompany
{
    return new FiscalCompany([
        'external_business_id' => 'business-test',
        'cuit' => '30712345671',
        'legal_name' => 'Empresa Test',
        'fiscal_condition' => $condition,
        'environment' => 'testing',
        'default_point_of_sale' => 1,
        'default_voucher_type' => null,
        'enabled' => true,
    ]);
}

/**
 * @return array<string, string>
 */
function fiscalVoucherReceiver(string $ivaCondition): array
{
    return [
        'name' => 'Cliente Test',
        'document_type' => 'CUIT',
        'document_number' => '30712345671',
        'iva_condition' => $ivaCondition,
    ];
}
