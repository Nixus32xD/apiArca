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
        'authorization_type',
        'authorization_code',
        'authorization_expires_at',
        'cae',
        'cae_expires_at',
        'caea_period',
        'caea_order',
        'caea_from',
        'caea_to',
        'caea_due_date',
        'caea_report_deadline',
        'fiscal_status',
        'idempotency_key',
        'normalized_payload',
        'request_payload',
        'response_payload',
        'raw_request',
        'raw_response',
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
            'authorization_expires_at' => 'date',
            'cae_expires_at' => 'date',
            'caea_order' => 'integer',
            'caea_from' => 'integer',
            'caea_to' => 'integer',
            'caea_due_date' => 'date',
            'caea_report_deadline' => 'date',
            'normalized_payload' => 'array',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'raw_request' => 'array',
            'raw_response' => 'array',
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
