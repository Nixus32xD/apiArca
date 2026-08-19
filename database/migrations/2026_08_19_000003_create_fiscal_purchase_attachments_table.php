<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_purchase_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_purchase_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('storage_key')->unique();
            $table->string('sha256', 64);
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->index(['fiscal_purchase_id', 'uploaded_at']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_purchase_attachments');
    }
};
