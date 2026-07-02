<?php

namespace App\Http\Resources;

use App\Services\Fiscal\FiscalVoucherResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalPurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->company?->external_business_id,
            'company' => [
                'id' => $this->company?->id,
                'cuit' => $this->company?->cuit,
                'legal_name' => $this->company?->legal_name,
            ],
            'origin' => [
                'type' => $this->origin_type,
                'id' => $this->origin_id,
            ],
            'voucher_date' => $this->voucher_date?->toDateString(),
            'accounting_date' => $this->accounting_date?->toDateString(),
            'document_type' => $this->document_type,
            'document_kind' => FiscalVoucherResolver::documentKindForVoucher((int) $this->voucher_type),
            'cbte_type' => $this->voucher_type,
            'point_of_sale' => $this->point_of_sale,
            'number' => $this->document_number,
            'supplier' => [
                'cuit' => $this->supplier_cuit,
                'name' => $this->supplier_name,
                'iva_condition' => $this->supplier_iva_condition,
            ],
            'amounts' => [
                'imp_total' => $this->imp_total,
                'imp_neto' => $this->imp_neto,
                'imp_iva' => $this->imp_iva,
                'imp_trib' => $this->imp_trib,
                'imp_op_ex' => $this->imp_op_ex,
                'imp_tot_conc' => $this->imp_tot_conc,
                'iva_items' => $this->whenLoaded('ivaItems', fn () => $this->ivaItems->map(fn ($item) => [
                    'id' => $item->iva_id,
                    'rate' => $item->rate,
                    'base_imp' => $item->base_imp,
                    'importe' => $item->importe,
                ])->values()),
                'sign' => FiscalVoucherResolver::signForVoucher((int) $this->voucher_type),
            ],
            'currency' => $this->currency,
            'currency_rate' => $this->currency_rate,
            'payment' => [
                'method' => $this->payment_method,
                'reference' => $this->payment_reference,
            ],
            'associated_vouchers' => $this->associated_vouchers,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
