<?php

namespace App\Http\Requests\Fiscal;

use App\Support\FiscalPointOfSale;
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
            'point_of_sale' => FiscalPointOfSale::requiredRules(),
            'cbte_type' => ['required', 'integer', 'min:1'],
        ];
    }
}
