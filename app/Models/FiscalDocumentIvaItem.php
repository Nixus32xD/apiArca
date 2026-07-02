<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentIvaItem extends Model
{
    protected $fillable = [
        'fiscal_document_id',
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

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
