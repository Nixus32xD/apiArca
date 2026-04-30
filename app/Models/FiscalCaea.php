<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalCaea extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'fiscal_company_id',
        'code',
        'period',
        'order',
        'point_of_sale',
        'voucher_type',
        'valid_from',
        'valid_to',
        'due_date',
        'report_deadline',
        'report_status',
        'reported_at',
        'without_movement_reported_at',
        'request_payload',
        'response_payload',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'point_of_sale' => 'integer',
            'voucher_type' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'due_date' => 'date',
            'report_deadline' => 'date',
            'reported_at' => 'datetime',
            'without_movement_reported_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }
}
