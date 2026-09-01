<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalSequenceReservation extends Model
{
    protected $fillable = [
        'fiscal_company_id',
        'fiscal_document_id',
        'point_of_sale',
        'voucher_type',
        'document_number',
    ];
}
