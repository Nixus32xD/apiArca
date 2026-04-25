<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_company_id')->constrained()->cascadeOnDelete();
            $table->longText('certificate');
            $table->longText('private_key');
            $table->text('passphrase')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['fiscal_company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_credentials');
    }
};
