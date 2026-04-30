<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FiscalCompany extends Model
{
    protected $fillable = [
        'external_business_id',
        'cuit',
        'legal_name',
        'environment',
        'default_point_of_sale',
        'default_voucher_type',
        'enabled',
        'onboarding_metadata',
    ];

    protected function casts(): array
    {
        return [
            'default_point_of_sale' => 'integer',
            'default_voucher_type' => 'integer',
            'enabled' => 'boolean',
            'onboarding_metadata' => 'array',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(FiscalCredential::class);
    }

    public function activeCredential(): HasOne
    {
        return $this->hasOne(FiscalCredential::class)->where('active', true)->latestOfMany();
    }

    public function accessTickets(): HasMany
    {
        return $this->hasMany(AccessTicket::class);
    }

    public function caeas(): HasMany
    {
        return $this->hasMany(FiscalCaea::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }
}
