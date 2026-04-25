<?php

namespace App\Http\Requests\Fiscal;

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
            'external_business_id' => ['required', 'string', 'max:120'],
            'cuit' => ['required', 'digits:11'],
            'legal_name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'in:testing,production'],
            'default_point_of_sale' => ['nullable', 'integer', 'min:1', 'max:99998'],
            'default_voucher_type' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['nullable', 'boolean'],
            'onboarding_metadata' => ['nullable', 'array'],
        ];
    }
}
