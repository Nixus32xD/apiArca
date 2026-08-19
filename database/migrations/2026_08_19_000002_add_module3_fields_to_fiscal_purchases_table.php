<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_purchases', function (Blueprint $table): void {
            if (! Schema::hasColumn('fiscal_purchases', 'category')) {
                $table->string('category', 120)->nullable()->after('origin_id');
            }

            if (! Schema::hasColumn('fiscal_purchases', 'concept')) {
                $table->string('concept')->nullable()->after('category');
            }

            if (! Schema::hasColumn('fiscal_purchases', 'payment_status')) {
                $table->string('payment_status', 20)->default('pending')->after('payment_reference');
            }

            if (! Schema::hasColumn('fiscal_purchases', 'due_date')) {
                $table->date('due_date')->nullable()->after('payment_status');
            }

            if (! Schema::hasColumn('fiscal_purchases', 'trib_items')) {
                $table->json('trib_items')->nullable()->after('associated_vouchers');
            }

            if (! Schema::hasColumn('fiscal_purchases', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable()->after('trib_items');
            }

            if (! Schema::hasColumn('fiscal_purchases', 'idempotency_payload_hash')) {
                $table->string('idempotency_payload_hash', 64)->nullable()->after('idempotency_key');
            }
        });

        if (! $this->hasIndex('fiscal_purchases', 'fiscal_purchases_company_idempotency_unique')) {
            Schema::table('fiscal_purchases', function (Blueprint $table): void {
                $table->unique(['fiscal_company_id', 'idempotency_key'], 'fiscal_purchases_company_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('fiscal_purchases', 'fiscal_purchases_company_idempotency_unique')) {
            Schema::table('fiscal_purchases', function (Blueprint $table): void {
                $table->dropUnique('fiscal_purchases_company_idempotency_unique');
            });
        }

        $columns = array_values(array_filter([
            'category',
            'concept',
            'payment_status',
            'due_date',
            'trib_items',
            'idempotency_key',
            'idempotency_payload_hash',
        ], fn (string $column): bool => Schema::hasColumn('fiscal_purchases', $column)));

        if ($columns !== []) {
            Schema::table('fiscal_purchases', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
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
