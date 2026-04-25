<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('fiscal_documents', 'fiscal_documents_number_unique')) {
            Schema::table('fiscal_documents', function (Blueprint $table): void {
                $table->dropUnique('fiscal_documents_number_unique');
            });
        }

        if (! Schema::hasIndex('fiscal_documents', 'fiscal_documents_number_index')) {
            Schema::table('fiscal_documents', function (Blueprint $table): void {
                $table->index(['fiscal_company_id', 'point_of_sale', 'voucher_type', 'document_number'], 'fiscal_documents_number_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('fiscal_documents', 'fiscal_documents_number_index')) {
            Schema::table('fiscal_documents', function (Blueprint $table): void {
                $table->dropIndex('fiscal_documents_number_index');
            });
        }

        if (! Schema::hasIndex('fiscal_documents', 'fiscal_documents_number_unique')) {
            Schema::table('fiscal_documents', function (Blueprint $table): void {
                $table->unique(['fiscal_company_id', 'point_of_sale', 'voucher_type', 'document_number'], 'fiscal_documents_number_unique');
            });
        }
    }
};
