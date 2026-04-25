<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

class ByOriginFiscalDocumentRequest extends FormRequest
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
            'origin_type' => ['required', 'string', 'in:sale,payment,manual'],
            'origin_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
