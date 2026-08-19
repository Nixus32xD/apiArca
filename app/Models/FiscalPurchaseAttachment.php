<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPurchaseAttachment extends Model
{
    protected $fillable = [
        'fiscal_purchase_id',
        'original_name',
        'mime',
        'size',
        'storage_key',
        'sha256',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(FiscalPurchase::class, 'fiscal_purchase_id');
    }
}
