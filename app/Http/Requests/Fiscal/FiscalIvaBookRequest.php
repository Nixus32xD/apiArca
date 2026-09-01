<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

class FiscalIvaBookRequest extends FormRequest
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
            'external_fiscal_id' => ['required_without_all:business_id,external_business_id', 'string', 'max:120'],
            'business_id' => ['required_without_all:external_business_id,external_fiscal_id', 'string', 'max:120'],
            'external_business_id' => ['required_without_all:business_id,external_fiscal_id', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
