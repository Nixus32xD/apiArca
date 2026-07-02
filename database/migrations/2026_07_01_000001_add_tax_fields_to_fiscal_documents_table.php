<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('fiscal_documents', 'voucher_date')) {
                $table->date('voucher_date')->nullable()->after('document_number');
            }

            if (! Schema::hasColumn('fiscal_documents', 'customer_doc_type')) {
                $table->unsignedInteger('customer_doc_type')->nullable()->after('voucher_date');
            }

            if (! Schema::hasColumn('fiscal_documents', 'customer_doc_number')) {
                $table->string('customer_doc_number', 30)->nullable()->after('customer_doc_type');
            }

            if (! Schema::hasColumn('fiscal_documents', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_doc_number');
            }

            if (! Schema::hasColumn('fiscal_documents', 'customer_iva_condition')) {
                $table->string('customer_iva_condition', 80)->nullable()->after('customer_name');
            }

            if (! Schema::hasColumn('fiscal_documents', 'customer_tax_condition_id')) {
                $table->unsignedSmallInteger('customer_tax_condition_id')->nullable()->after('customer_iva_condition');
            }

            foreach (['imp_total', 'imp_neto', 'imp_iva', 'imp_trib', 'imp_op_ex', 'imp_tot_conc'] as $column) {
                if (! Schema::hasColumn('fiscal_documents', $column)) {
                    $table->decimal($column, 15, 2)->nullable()->after('customer_tax_condition_id');
                }
            }

            if (! Schema::hasColumn('fiscal_documents', 'payment_method')) {
                $table->string('payment_method', 30)->nullable()->after('imp_tot_conc');
            }

            if (! Schema::hasColumn('fiscal_documents', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('payment_method');
            }

            if (! Schema::hasColumn('fiscal_documents', 'payment_amount')) {
                $table->decimal('payment_amount', 15, 2)->nullable()->after('payment_reference');
            }

            if (! Schema::hasColumn('fiscal_documents', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('payment_amount');
            }
        });

        if (! $this->hasIndex('fiscal_documents', 'fiscal_documents_company_voucher_date_index')) {
            Schema::table('fiscal_documents', function (Blueprint $table): void {
                $table->index(['fiscal_company_id', 'voucher_date'], 'fiscal_documents_company_voucher_date_index');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if ($this->hasIndex('fiscal_documents', 'fiscal_documents_company_voucher_date_index')) {
            Schema::table('fiscal_documents', function (Blueprint $table): void {
                $table->dropIndex('fiscal_documents_company_voucher_date_index');
            });
        }

        $columns = array_values(array_filter([
            'voucher_date',
            'customer_doc_type',
            'customer_doc_number',
            'customer_name',
            'customer_iva_condition',
            'customer_tax_condition_id',
            'imp_total',
            'imp_neto',
            'imp_iva',
            'imp_trib',
            'imp_op_ex',
            'imp_tot_conc',
            'payment_method',
            'payment_reference',
            'payment_amount',
            'paid_at',
        ], fn (string $column): bool => Schema::hasColumn('fiscal_documents', $column)));

        if ($columns !== []) {
            Schema::table('fiscal_documents', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function backfill(): void
    {
        DB::table('fiscal_documents')
            ->whereNotNull('normalized_payload')
            ->orderBy('id')
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $document) {
                    $payload = json_decode((string) $document->normalized_payload, true);

                    if (! is_array($payload)) {
                        continue;
                    }

                    DB::table('fiscal_documents')
                        ->where('id', $document->id)
                        ->update([
                            'voucher_date' => $this->dateFromAfip(data_get($payload, 'voucher_date')),
                            'customer_doc_type' => data_get($payload, 'customer.doc_type'),
                            'customer_doc_number' => data_get($payload, 'customer.document_number'),
                            'customer_name' => data_get($payload, 'customer.name'),
                            'customer_iva_condition' => data_get($payload, 'customer.iva_condition'),
                            'customer_tax_condition_id' => data_get($payload, 'customer.tax_condition_id'),
                            'imp_total' => data_get($payload, 'amounts.imp_total'),
                            'imp_neto' => data_get($payload, 'amounts.imp_neto'),
                            'imp_iva' => data_get($payload, 'amounts.imp_iva'),
                            'imp_trib' => data_get($payload, 'amounts.imp_trib'),
                            'imp_op_ex' => data_get($payload, 'amounts.imp_op_ex'),
                            'imp_tot_conc' => data_get($payload, 'amounts.imp_tot_conc'),
                            'payment_method' => data_get($payload, 'payment.method'),
                            'payment_reference' => data_get($payload, 'payment.reference'),
                            'payment_amount' => data_get($payload, 'payment.amount'),
                            'paid_at' => data_get($payload, 'payment.paid_at'),
                        ]);
                }
            });
    }

    private function dateFromAfip(mixed $value): ?string
    {
        if (! is_scalar($value) || ! preg_match('/^\d{8}$/', (string) $value)) {
            return null;
        }

        return Carbon::createFromFormat('Ymd', (string) $value)->toDateString();
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $indexName) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
