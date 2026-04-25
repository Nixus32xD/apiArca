<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

class GenerateFiscalCredentialCsrRequest extends FormRequest
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
            'key_name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'common_name' => ['nullable', 'string', 'max:64'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'country_name' => ['nullable', 'string', 'size:2'],
            'passphrase' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
