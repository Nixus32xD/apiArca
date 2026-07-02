<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalPurchase extends Model
{
    protected $fillable = [
        'fiscal_company_id',
        'origin_type',
        'origin_id',
        'voucher_date',
        'accounting_date',
        'voucher_type',
        'document_type',
        'point_of_sale',
        'document_number',
        'supplier_cuit',
        'supplier_name',
        'supplier_iva_condition',
        'imp_total',
        'imp_neto',
        'imp_iva',
        'imp_trib',
        'imp_op_ex',
        'imp_tot_conc',
        'currency',
        'currency_rate',
        'payment_method',
        'payment_reference',
        'associated_vouchers',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'accounting_date' => 'date',
            'voucher_type' => 'integer',
            'point_of_sale' => 'integer',
            'document_number' => 'integer',
            'imp_total' => 'decimal:2',
            'imp_neto' => 'decimal:2',
            'imp_iva' => 'decimal:2',
            'imp_trib' => 'decimal:2',
            'imp_op_ex' => 'decimal:2',
            'imp_tot_conc' => 'decimal:2',
            'currency_rate' => 'decimal:6',
            'associated_vouchers' => 'array',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }

    public function ivaItems(): HasMany
    {
        return $this->hasMany(FiscalPurchaseIvaItem::class);
    }
}
