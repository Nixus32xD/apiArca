<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_company_id')->constrained()->cascadeOnDelete();
            $table->string('origin_type')->nullable();
            $table->string('origin_id')->nullable();
            $table->string('document_type')->nullable();
            $table->unsignedInteger('point_of_sale');
            $table->unsignedInteger('voucher_type');
            $table->unsignedInteger('concept')->default(1);
            $table->unsignedBigInteger('document_number')->nullable();
            $table->string('status', 40)->default('draft');
            $table->string('cae')->nullable();
            $table->date('cae_expires_at')->nullable();
            $table->string('idempotency_key', 120);
            $table->json('normalized_payload')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('observations')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_company_id', 'idempotency_key'], 'fiscal_documents_company_idempotency_unique');
            $table->index(['fiscal_company_id', 'point_of_sale', 'voucher_type', 'document_number'], 'fiscal_documents_number_index');
            $table->index(['fiscal_company_id', 'origin_type', 'origin_id'], 'fiscal_documents_origin_index');
            $table->index(['fiscal_company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
