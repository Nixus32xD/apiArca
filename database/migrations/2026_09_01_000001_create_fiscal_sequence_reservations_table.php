<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_sequence_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('point_of_sale');
            $table->unsignedInteger('voucher_type');
            $table->unsignedBigInteger('document_number');
            $table->timestamps();

            $table->unique('fiscal_document_id', 'fiscal_sequence_reservation_document_unique');
            $table->unique(
                ['fiscal_company_id', 'point_of_sale', 'voucher_type', 'document_number'],
                'fiscal_sequence_reservation_number_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_sequence_reservations');
    }
};
