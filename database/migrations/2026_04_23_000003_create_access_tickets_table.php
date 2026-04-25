<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_company_id')->constrained()->cascadeOnDelete();
            $table->string('service', 32);
            $table->longText('token');
            $table->longText('sign');
            $table->timestamp('generation_time')->nullable();
            $table->timestamp('expiration_time');
            $table->unsignedInteger('reused_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_company_id', 'service']);
            $table->index('expiration_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_tickets');
    }
};
