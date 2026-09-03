<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('fiscal_documents', 'fiscal_documents_number_unique')) {
            return;
        }

        $duplicates = DB::table('fiscal_documents')
            ->select(['fiscal_company_id', 'point_of_sale', 'voucher_type', 'document_number'])
            ->selectRaw('COUNT(*) as duplicate_count')
            ->whereNotNull('document_number')
            ->groupBy(['fiscal_company_id', 'point_of_sale', 'voucher_type', 'document_number'])
            ->havingRaw('COUNT(*) > 1')
            ->limit(20)
            ->get();

        if ($duplicates->isNotEmpty()) {
            Log::critical('Fiscal document number unique preflight failed.', [
                'duplicates' => $duplicates->all(),
            ]);

            throw new RuntimeException(
                'No se restauró la unicidad de numeración fiscal: existen comprobantes duplicados. Revisa el log y corrige los registros manualmente antes de reintentar.'
            );
        }

        $hasNumberIndex = Schema::hasIndex('fiscal_documents', 'fiscal_documents_number_index');

        Schema::table('fiscal_documents', function (Blueprint $table) use ($hasNumberIndex): void {
            if ($hasNumberIndex) {
                $table->dropIndex('fiscal_documents_number_index');
            }

            $table->unique(
                ['fiscal_company_id', 'point_of_sale', 'voucher_type', 'document_number'],
                'fiscal_documents_number_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('fiscal_documents', 'fiscal_documents_number_unique')) {
            return;
        }

        $hasNumberIndex = Schema::hasIndex('fiscal_documents', 'fiscal_documents_number_index');

        Schema::table('fiscal_documents', function (Blueprint $table) use ($hasNumberIndex): void {
            $table->dropUnique('fiscal_documents_number_unique');

            if (! $hasNumberIndex) {
                $table->index(
                    ['fiscal_company_id', 'point_of_sale', 'voucher_type', 'document_number'],
                    'fiscal_documents_number_index',
                );
            }
        });
    }
};
