<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalDocument extends Model
{
    protected $fillable = [
        'fiscal_company_id',
        'origin_type',
        'origin_id',
        'document_type',
        'point_of_sale',
        'voucher_type',
        'concept',
        'document_number',
        'status',
        'cae',
        'cae_expires_at',
        'idempotency_key',
        'normalized_payload',
        'request_payload',
        'response_payload',
        'error_code',
        'error_message',
        'observations',
        'metadata',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'point_of_sale' => 'integer',
            'voucher_type' => 'integer',
            'concept' => 'integer',
            'document_number' => 'integer',
            'cae_expires_at' => 'date',
            'normalized_payload' => 'array',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'observations' => 'array',
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(FiscalDocumentAttempt::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(FiscalDocumentEvent::class);
    }
}
