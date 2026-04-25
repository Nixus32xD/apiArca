<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_api_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 20);
            $table->string('operation', 120);
            $table->string('endpoint')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request_summary')->nullable();
            $table->json('response_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['fiscal_company_id', 'created_at']);
            $table->index(['fiscal_document_id', 'created_at']);
            $table->index(['direction', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_api_logs');
    }
};
