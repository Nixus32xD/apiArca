<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalApiLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'fiscal_company_id',
        'fiscal_document_id',
        'direction',
        'operation',
        'endpoint',
        'status_code',
        'duration_ms',
        'request_summary',
        'response_summary',
        'error_message',
        'trace_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'request_summary' => 'array',
            'response_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
