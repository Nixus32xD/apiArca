<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

class StoreFiscalDocumentRequest extends FormRequest
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
            'origin_type' => ['nullable', 'string', 'in:sale,payment,manual'],
            'origin_id' => ['nullable', 'string', 'max:120'],
            'sale_id' => ['nullable', 'string', 'max:120'],
            'payment_id' => ['nullable', 'string', 'max:120'],
            'document_type' => ['nullable', 'string', 'max:80'],
            'point_of_sale' => ['nullable', 'integer', 'min:1', 'max:99998'],
            'concept' => ['nullable', 'integer', 'in:1,2,3'],
            'cbte_type' => ['nullable', 'integer', 'min:1'],
            'customer' => ['nullable', 'array'],
            'customer.doc_type' => ['nullable', 'integer', 'min:0'],
            'customer.doc_number' => ['nullable', 'integer', 'min:0'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.tax_condition' => ['nullable', 'string', 'max:80'],
            'customer.tax_condition_id' => ['nullable', 'integer', 'min:1'],
            'customer.email' => ['nullable', 'email', 'max:255'],
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
            'voucher_date' => ['nullable', 'date'],
            'service_dates' => ['nullable', 'array'],
            'service_dates.from' => ['nullable', 'date'],
            'service_dates.to' => ['nullable', 'date'],
            'service_dates.payment_due_date' => ['nullable', 'date'],
            'items' => ['nullable', 'array'],
            'associated_vouchers' => ['nullable', 'array'],
            'optional_fields' => ['nullable', 'array'],
            'activities' => ['nullable', 'array'],
            'activities.*' => ['required'],
            'metadata' => ['nullable', 'array'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ];
    }
}
