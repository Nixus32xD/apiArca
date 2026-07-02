<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

class StoreFiscalPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_id' => ['required_without:external_business_id', 'string', 'max:120'],
            'external_business_id' => ['required_without:business_id', 'string', 'max:120'],
            'origin' => ['nullable', 'array'],
            'origin.type' => ['required_with:origin', 'string', 'in:purchase,manual'],
            'origin.id' => ['nullable', 'string', 'max:120'],
            'origin_type' => ['nullable', 'string', 'in:purchase,manual'],
            'origin_id' => ['nullable', 'string', 'max:120'],
            'voucher_date' => ['required', 'date'],
            'accounting_date' => ['nullable', 'date'],
            'cbte_type' => ['required', 'integer', 'min:1'],
            'point_of_sale' => ['required', 'integer', 'min:1', 'max:99998'],
            'document_number' => ['required', 'integer', 'min:1'],
            'supplier' => ['required', 'array'],
            'supplier.cuit' => ['required', 'digits:11'],
            'supplier.name' => ['nullable', 'string', 'max:255'],
            'supplier.iva_condition' => ['nullable', 'string', 'max:80'],
            'amounts' => ['required', 'array'],
            'amounts.imp_total' => ['required', 'numeric', 'min:0'],
            'amounts.imp_neto' => ['required', 'numeric', 'min:0'],
            'amounts.imp_iva' => ['nullable', 'numeric', 'min:0'],
            'amounts.imp_trib' => ['nullable', 'numeric', 'min:0'],
            'amounts.imp_op_ex' => ['nullable', 'numeric', 'min:0'],
            'amounts.imp_tot_conc' => ['nullable', 'numeric', 'min:0'],
            'amounts.iva_items' => ['nullable', 'array'],
            'amounts.iva_items.*.id' => ['required_with:amounts.iva_items', 'integer'],
            'amounts.iva_items.*.base_imp' => ['required_with:amounts.iva_items', 'numeric', 'min:0'],
            'amounts.iva_items.*.importe' => ['required_with:amounts.iva_items', 'numeric', 'min:0'],
            'amounts.trib_items' => ['nullable', 'array'],
            'amounts.trib_items.*.id' => ['required_with:amounts.trib_items', 'integer'],
            'amounts.trib_items.*.desc' => ['nullable', 'string', 'max:255'],
            'amounts.trib_items.*.base_imp' => ['nullable', 'numeric', 'min:0'],
            'amounts.trib_items.*.alic' => ['nullable', 'numeric', 'min:0'],
            'amounts.trib_items.*.importe' => ['required_with:amounts.trib_items', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'currency_rate' => ['nullable', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', 'string', 'in:cash,efectivo,transfer,transferencia,bank_transfer,debit_card,debito,credit_card,credito,other,otro'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'associated_vouchers' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
