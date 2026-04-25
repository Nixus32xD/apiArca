<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fiscal_document_attempts')) {
            if (! $this->hasIndex('fiscal_document_attempts', 'fdoc_attempt_doc_attempt_unique')) {
                Schema::table('fiscal_document_attempts', function (Blueprint $table): void {
                    $table->unique(['fiscal_document_id', 'attempt_number'], 'fdoc_attempt_doc_attempt_unique');
                });
            }

            return;
        }

        Schema::create('fiscal_document_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('operation', 80);
            $table->string('status', 40);
            $table->string('environment', 20)->nullable();
            $table->string('endpoint')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_document_id', 'attempt_number'], 'fdoc_attempt_doc_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_attempts');
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
