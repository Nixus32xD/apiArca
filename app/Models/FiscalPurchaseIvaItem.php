<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPurchaseIvaItem extends Model
{
    protected $fillable = [
        'fiscal_purchase_id',
        'iva_id',
        'rate',
        'base_imp',
        'importe',
    ];

    protected function casts(): array
    {
        return [
            'iva_id' => 'integer',
            'rate' => 'decimal:2',
            'base_imp' => 'decimal:2',
            'importe' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(FiscalPurchase::class, 'fiscal_purchase_id');
    }
}
