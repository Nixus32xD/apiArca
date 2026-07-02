<?php

namespace App\Services\Fiscal;

use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Models\FiscalPurchase;
use Illuminate\Support\Collection;

class FiscalIvaBookService
{
    /**
     * @return array<string, mixed>
     */
    public function sales(FiscalCompany $company, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = $company->documents()
            ->with(['company', 'ivaItems'])
            ->where('status', 'authorized')
            ->whereNotNull('document_number')
            ->orderBy('voucher_date')
            ->orderBy('point_of_sale')
            ->orderBy('document_number');

        if ($dateFrom) {
            $query->whereDate('voucher_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('voucher_date', '<=', $dateTo);
        }

        /** @var Collection<int, FiscalDocument> $documents */
        $documents = $query->get();
        $records = $documents->map(fn (FiscalDocument $document): array => $this->saleRecord($document))->values();

        return [
            'company' => $this->companyPayload($company),
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'records' => $records,
            'totals' => $this->totals($records),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function purchases(FiscalCompany $company, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = $company->purchases()
            ->with(['company', 'ivaItems'])
            ->orderBy('voucher_date')
            ->orderBy('point_of_sale')
            ->orderBy('document_number');

        if ($dateFrom) {
            $query->whereDate('voucher_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('voucher_date', '<=', $dateTo);
        }

        /** @var Collection<int, FiscalPurchase> $purchases */
        $purchases = $query->get();
        $records = $purchases->map(fn (FiscalPurchase $purchase): array => $this->purchaseRecord($purchase))->values();

        return [
            'company' => $this->companyPayload($company),
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'records' => $records,
            'totals' => $this->totals($records),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleRecord(FiscalDocument $document): array
    {
        $sign = FiscalVoucherResolver::signForVoucher((int) $document->voucher_type);

        return [
            'id' => $document->id,
            'voucher_date' => $document->voucher_date?->toDateString(),
            'document_type' => $document->document_type,
            'document_kind' => FiscalVoucherResolver::documentKindForVoucher((int) $document->voucher_type),
            'cbte_type' => $document->voucher_type,
            'point_of_sale' => $document->point_of_sale,
            'number' => $document->document_number,
            'counterparty_cuit' => $document->customer_doc_number,
            'counterparty_name' => $document->customer_name,
            'counterparty_iva_condition' => $document->customer_iva_condition,
            'authorization_type' => $document->authorization_type,
            'authorization_code' => $document->authorization_code,
            'authorization_expires_at' => $document->authorization_expires_at?->toDateString(),
            'cae' => $document->cae,
            'cae_expires_at' => $document->cae_expires_at?->toDateString(),
            'amounts' => $this->signedAmounts($document, $sign),
            'iva_items' => $this->signedIvaItems($document->ivaItems, $sign),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseRecord(FiscalPurchase $purchase): array
    {
        $sign = FiscalVoucherResolver::signForVoucher((int) $purchase->voucher_type);

        return [
            'id' => $purchase->id,
            'voucher_date' => $purchase->voucher_date?->toDateString(),
            'accounting_date' => $purchase->accounting_date?->toDateString(),
            'document_type' => $purchase->document_type,
            'document_kind' => FiscalVoucherResolver::documentKindForVoucher((int) $purchase->voucher_type),
            'cbte_type' => $purchase->voucher_type,
            'point_of_sale' => $purchase->point_of_sale,
            'number' => $purchase->document_number,
            'counterparty_cuit' => $purchase->supplier_cuit,
            'counterparty_name' => $purchase->supplier_name,
            'counterparty_iva_condition' => $purchase->supplier_iva_condition,
            'amounts' => $this->signedAmounts($purchase, $sign),
            'iva_items' => $this->signedIvaItems($purchase->ivaItems, $sign),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @return array<string, mixed>
     */
    private function totals(Collection $records): array
    {
        $totals = [
            'imp_total' => 0.0,
            'imp_neto' => 0.0,
            'imp_iva' => 0.0,
            'imp_trib' => 0.0,
            'imp_op_ex' => 0.0,
            'imp_tot_conc' => 0.0,
            'iva_by_aliquot' => [],
        ];

        foreach ($records as $record) {
            foreach (['imp_total', 'imp_neto', 'imp_iva', 'imp_trib', 'imp_op_ex', 'imp_tot_conc'] as $key) {
                $totals[$key] += (float) data_get($record, "amounts.$key", 0);
            }

            foreach ((array) ($record['iva_items'] ?? []) as $item) {
                $ivaId = (int) ($item['id'] ?? 0);

                if (! isset($totals['iva_by_aliquot'][$ivaId])) {
                    $totals['iva_by_aliquot'][$ivaId] = [
                        'id' => $ivaId,
                        'rate' => $item['rate'] ?? null,
                        'base_imp' => 0.0,
                        'importe' => 0.0,
                    ];
                }

                $totals['iva_by_aliquot'][$ivaId]['base_imp'] += (float) ($item['base_imp'] ?? 0);
                $totals['iva_by_aliquot'][$ivaId]['importe'] += (float) ($item['importe'] ?? 0);
            }
        }

        foreach (['imp_total', 'imp_neto', 'imp_iva', 'imp_trib', 'imp_op_ex', 'imp_tot_conc'] as $key) {
            $totals[$key] = $this->decimal($totals[$key]);
        }

        $totals['iva_by_aliquot'] = array_values(array_map(fn (array $item): array => [
            'id' => $item['id'],
            'rate' => $item['rate'],
            'base_imp' => $this->decimal($item['base_imp']),
            'importe' => $this->decimal($item['importe']),
        ], $totals['iva_by_aliquot']));

        return $totals;
    }

    /**
     * @return array<string, string>
     */
    private function signedAmounts(FiscalDocument|FiscalPurchase $record, int $sign): array
    {
        return [
            'imp_total' => $this->decimal((float) $record->imp_total * $sign),
            'imp_neto' => $this->decimal((float) $record->imp_neto * $sign),
            'imp_iva' => $this->decimal((float) $record->imp_iva * $sign),
            'imp_trib' => $this->decimal((float) $record->imp_trib * $sign),
            'imp_op_ex' => $this->decimal((float) $record->imp_op_ex * $sign),
            'imp_tot_conc' => $this->decimal((float) $record->imp_tot_conc * $sign),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function signedIvaItems(Collection $items, int $sign): array
    {
        return $items
            ->map(fn ($item): array => [
                'id' => $item->iva_id,
                'rate' => $item->rate,
                'base_imp' => $this->decimal((float) $item->base_imp * $sign),
                'importe' => $this->decimal((float) $item->importe * $sign),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function companyPayload(FiscalCompany $company): array
    {
        return [
            'id' => $company->id,
            'business_id' => $company->external_business_id,
            'cuit' => $company->cuit,
            'legal_name' => $company->legal_name,
            'fiscal_condition' => $company->fiscal_condition,
        ];
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
