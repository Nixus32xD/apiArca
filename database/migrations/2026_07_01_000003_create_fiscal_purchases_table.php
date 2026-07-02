<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_company_id')->constrained()->cascadeOnDelete();
            $table->string('origin_type')->nullable();
            $table->string('origin_id')->nullable();
            $table->date('voucher_date');
            $table->date('accounting_date')->nullable();
            $table->unsignedInteger('voucher_type');
            $table->string('document_type', 80);
            $table->unsignedInteger('point_of_sale');
            $table->unsignedBigInteger('document_number');
            $table->string('supplier_cuit', 11);
            $table->string('supplier_name')->nullable();
            $table->string('supplier_iva_condition', 80)->nullable();
            $table->decimal('imp_total', 15, 2);
            $table->decimal('imp_neto', 15, 2);
            $table->decimal('imp_iva', 15, 2)->default(0);
            $table->decimal('imp_trib', 15, 2)->default(0);
            $table->decimal('imp_op_ex', 15, 2)->default(0);
            $table->decimal('imp_tot_conc', 15, 2)->default(0);
            $table->string('currency', 3)->default('PES');
            $table->decimal('currency_rate', 15, 6)->default(1);
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference')->nullable();
            $table->json('associated_vouchers')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['fiscal_company_id', 'supplier_cuit', 'voucher_type', 'point_of_sale', 'document_number'],
                'fiscal_purchases_company_supplier_voucher_unique',
            );
            $table->index(['fiscal_company_id', 'voucher_date']);
            $table->index(['fiscal_company_id', 'origin_type', 'origin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_purchases');
    }
};
