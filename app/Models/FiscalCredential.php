<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalCredential extends Model
{
    protected $fillable = [
        'fiscal_company_id',
        'key_name',
        'certificate',
        'private_key',
        'passphrase',
        'csr',
        'certificate_expires_at',
        'active',
        'status',
        'metadata',
    ];

    protected $hidden = [
        'certificate',
        'private_key',
        'passphrase',
    ];

    protected function casts(): array
    {
        return [
            'certificate' => 'encrypted',
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'certificate_expires_at' => 'datetime',
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }
}
