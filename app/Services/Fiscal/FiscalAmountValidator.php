<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;

class FiscalAmountValidator
{
    /**
     * Standard IVA ids returned by FEParamGetTiposIva.
     *
     * @var array<int, float>
     */
    public const IVA_RATES = [
        3 => 0.0,
        4 => 10.5,
        5 => 21.0,
        6 => 27.0,
        8 => 5.0,
        9 => 2.5,
    ];

    /**
     * @param  array<string, mixed>  $amounts
     */
    public function validateSale(int $voucherType, array $amounts): void
    {
        $this->validate($voucherType, $amounts);
    }

    /**
     * @param  array<string, mixed>  $amounts
     */
    public function validatePurchase(int $voucherType, array $amounts): void
    {
        $this->validate($voucherType, $amounts);
    }

    public function ivaRateFor(int $ivaId): ?float
    {
        return self::IVA_RATES[$ivaId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $amounts
     */
    private function validate(int $voucherType, array $amounts): void
    {
        if (FiscalVoucherResolver::isClassC($voucherType)) {
            $this->validateClassC($amounts);

            return;
        }

        $this->validateTotals($amounts, includeIvaAndExempt: true);
        $this->validateIvaItems($amounts);
        $this->validateTribItems($amounts);
    }

    /**
     * @param  array<string, mixed>  $amounts
     */
    private function validateClassC(array $amounts): void
    {
        if ($this->cents($amounts['imp_iva'] ?? 0) !== 0 || ($amounts['iva_items'] ?? []) !== []) {
            throw new FiscalException('Comprobantes tipo C must not include IVA.', 422, 'invoice_c_iva_not_allowed');
        }

        if ($this->cents($amounts['imp_tot_conc'] ?? 0) !== 0) {
            throw new FiscalException('Comprobantes tipo C must not include non-taxed net amounts.', 422, 'invoice_c_non_taxed_not_allowed');
        }

        if ($this->cents($amounts['imp_op_ex'] ?? 0) !== 0) {
            throw new FiscalException('Comprobantes tipo C must not include exempt amounts.', 422, 'invoice_c_exempt_not_allowed');
        }

        $this->validateTotals($amounts, includeIvaAndExempt: false);
        $this->validateTribItems($amounts);
    }

    /**
     * @param  array<string, mixed>  $amounts
     */
    private function validateTotals(array $amounts, bool $includeIvaAndExempt): void
    {
        $expected = $this->cents($amounts['imp_neto'] ?? 0)
            + $this->cents($amounts['imp_trib'] ?? 0);

        if ($includeIvaAndExempt) {
            $expected += $this->cents($amounts['imp_tot_conc'] ?? 0)
                + $this->cents($amounts['imp_op_ex'] ?? 0)
                + $this->cents($amounts['imp_iva'] ?? 0);
        }

        $this->assertCentsMatch(
            $this->cents($amounts['imp_total'] ?? 0),
            $expected,
            'ImpTotal must match the sum of its fiscal components.',
            'amount_total_mismatch',
        );
    }

    /**
     * @param  array<string, mixed>  $amounts
     */
    private function validateIvaItems(array $amounts): void
    {
        $items = $amounts['iva_items'] ?? [];
        $items = is_array($items) ? $items : [];
        $netCents = $this->cents($amounts['imp_neto'] ?? 0);
        $ivaCents = $this->cents($amounts['imp_iva'] ?? 0);

        if ($netCents > 0 && $items === []) {
            throw new FiscalException('IVA detail is required when net taxable amount is greater than zero.', 422, 'iva_items_required');
        }

        if ($items === []) {
            if ($ivaCents !== 0) {
                throw new FiscalException('ImpIVA requires IVA detail.', 422, 'iva_items_required');
            }

            return;
        }

        $seen = [];
        $baseSum = 0;
        $ivaSum = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ivaId = (int) ($item['Id'] ?? $item['id'] ?? 0);

            if (isset($seen[$ivaId])) {
                throw new FiscalException('IVA aliquot ids must not be repeated.', 422, 'iva_aliquot_duplicated', [
                    'iva_id' => $ivaId,
                ]);
            }

            $seen[$ivaId] = true;
            $base = $this->cents($item['BaseImp'] ?? $item['base_imp'] ?? 0);
            $importe = $this->cents($item['Importe'] ?? $item['importe'] ?? 0);
            $baseSum += $base;
            $ivaSum += $importe;

            $rate = $this->ivaRateFor($ivaId);

            if ($rate === null) {
                throw new FiscalException('Unsupported IVA aliquot id.', 422, 'unsupported_iva_id', [
                    'iva_id' => $ivaId,
                ]);
            }

            $expected = (int) round($base * ($rate / 100));
            $this->assertCentsMatch($importe, $expected, 'IVA amount does not match the selected aliquot.', 'iva_aliquot_amount_mismatch', [
                'iva_id' => $ivaId,
            ]);
        }

        $this->assertCentsMatch($ivaCents, $ivaSum, 'ImpIVA must match IVA detail sum.', 'iva_total_mismatch');
        $this->assertCentsMatch($netCents, $baseSum, 'ImpNeto must match IVA base sum.', 'iva_base_mismatch');
    }

    /**
     * @param  array<string, mixed>  $amounts
     */
    private function validateTribItems(array $amounts): void
    {
        $items = $amounts['trib_items'] ?? [];
        $items = is_array($items) ? $items : [];
        $tribCents = $this->cents($amounts['imp_trib'] ?? 0);

        if ($tribCents > 0 && $items === []) {
            throw new FiscalException('Tribute detail is required when ImpTrib is greater than zero.', 422, 'trib_items_required');
        }

        if ($items === []) {
            return;
        }

        $sum = 0;

        foreach ($items as $item) {
            if (is_array($item)) {
                $sum += $this->cents($item['Importe'] ?? $item['importe'] ?? 0);
            }
        }

        $this->assertCentsMatch($tribCents, $sum, 'ImpTrib must match tribute detail sum.', 'trib_total_mismatch');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertCentsMatch(int $actual, int $expected, string $message, string $errorCode, array $context = []): void
    {
        if (abs($actual - $expected) <= 1) {
            return;
        }

        throw new FiscalException($message, 422, $errorCode, [
            'actual' => $this->decimal($actual),
            'expected' => $this->decimal($expected),
            ...$context,
        ]);
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
