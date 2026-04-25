<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentAttempt extends Model
{
    protected $fillable = [
        'fiscal_document_id',
        'attempt_number',
        'operation',
        'status',
        'environment',
        'endpoint',
        'request_payload',
        'response_payload',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
        'duration_ms',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
