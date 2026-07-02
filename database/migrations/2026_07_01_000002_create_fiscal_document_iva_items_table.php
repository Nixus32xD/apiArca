<?php

use App\Services\Fiscal\FiscalAmountValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_document_iva_items')) {
            Schema::create('fiscal_document_iva_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fiscal_document_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('iva_id');
                $table->decimal('rate', 5, 2)->nullable();
                $table->decimal('base_imp', 15, 2);
                $table->decimal('importe', 15, 2);
                $table->timestamps();

                $table->unique(['fiscal_document_id', 'iva_id'], 'fdoc_iva_doc_rate_unique');
                $table->index('iva_id');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_iva_items');
    }

    private function backfill(): void
    {
        DB::table('fiscal_documents')
            ->whereNotNull('normalized_payload')
            ->orderBy('id')
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $document) {
                    $payload = json_decode((string) $document->normalized_payload, true);
                    $items = data_get(is_array($payload) ? $payload : [], 'amounts.iva_items', []);

                    if (! is_array($items)) {
                        continue;
                    }

                    foreach ($items as $item) {
                        if (! is_array($item)) {
                            continue;
                        }

                        $ivaId = (int) ($item['Id'] ?? $item['id'] ?? 0);

                        if ($ivaId <= 0) {
                            continue;
                        }

                        DB::table('fiscal_document_iva_items')->updateOrInsert(
                            [
                                'fiscal_document_id' => $document->id,
                                'iva_id' => $ivaId,
                            ],
                            [
                                'rate' => FiscalAmountValidator::IVA_RATES[$ivaId] ?? null,
                                'base_imp' => $item['BaseImp'] ?? $item['base_imp'] ?? 0,
                                'importe' => $item['Importe'] ?? $item['importe'] ?? 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ],
                        );
                    }
                }
            });
    }
};
