<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessTicket extends Model
{
    protected $fillable = [
        'fiscal_company_id',
        'service',
        'token',
        'sign',
        'generation_time',
        'expiration_time',
        'reused_count',
        'last_used_at',
        'metadata',
    ];

    protected $hidden = [
        'token',
        'sign',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'sign' => 'encrypted',
            'generation_time' => 'datetime',
            'expiration_time' => 'datetime',
            'last_used_at' => 'datetime',
            'reused_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }
}
