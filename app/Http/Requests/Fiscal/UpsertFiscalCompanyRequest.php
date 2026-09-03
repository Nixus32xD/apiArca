<?php

namespace App\Http\Requests\Fiscal;

use App\Support\FiscalPointOfSale;
use Illuminate\Foundation\Http\FormRequest;

class UpsertFiscalCompanyRequest extends FormRequest
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
            'external_fiscal_id' => ['nullable', 'string', 'max:120'],
            'external_business_id' => ['required_without:external_fiscal_id', 'string', 'max:120'],
            'cuit' => ['required', 'digits:11'],
            'legal_name' => ['required', 'string', 'max:255'],
            'fiscal_condition' => ['nullable', 'string', 'in:monotributo,responsable_inscripto,exento'],
            'environment' => ['required', 'string', 'in:testing,production'],
            'default_point_of_sale' => FiscalPointOfSale::nullableRules(),
            'default_voucher_type' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['nullable', 'boolean'],
            'onboarding_metadata' => ['nullable', 'array'],
        ];
    }
}
