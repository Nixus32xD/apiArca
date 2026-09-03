<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fiscal_documents', 'idempotency_payload_hash')) {
            return;
        }

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            // Nullable preserves historical fiscal records. Their replay hash
            // is derived lazily from normalized_payload when it is available.
            $table->string('idempotency_payload_hash', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fiscal_documents', 'idempotency_payload_hash')) {
            return;
        }

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn('idempotency_payload_hash');
        });
    }
};
