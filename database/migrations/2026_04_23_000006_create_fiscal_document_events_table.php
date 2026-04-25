<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_document_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['fiscal_document_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_events');
    }
};
