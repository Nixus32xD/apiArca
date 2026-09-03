<?php

namespace App\Http\Requests\Fiscal;

use App\Support\FiscalPointOfSale;
use Illuminate\Foundation\Http\FormRequest;

class ReconcileFiscalSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, int|string>>
     */
    public function rules(): array
    {
        return [
            'point_of_sale' => FiscalPointOfSale::requiredRules(),
            'cbte_type' => ['required', 'integer', 'min:1'],
        ];
    }
}
