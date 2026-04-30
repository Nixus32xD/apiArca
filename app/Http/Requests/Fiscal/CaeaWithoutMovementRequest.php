<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

class CaeaWithoutMovementRequest extends FormRequest
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
            'caea' => ['required', 'string', 'digits:14'],
            'point_of_sale' => ['required', 'integer', 'min:1', 'max:99998'],
            'cbte_type' => ['required', 'integer', 'min:1'],
        ];
    }
}
