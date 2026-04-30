<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_caeas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 14);
            $table->string('period', 6);
            $table->unsignedTinyInteger('order');
            $table->unsignedInteger('point_of_sale')->nullable();
            $table->unsignedInteger('voucher_type')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->date('due_date')->nullable();
            $table->date('report_deadline')->nullable();
            $table->string('report_status', 30)->default('pending');
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('without_movement_reported_at')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_company_id', 'period', 'order'], 'fiscal_caeas_company_period_order_unique');
            $table->index(['report_status', 'report_deadline']);
            $table->index(['fiscal_company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_caeas');
    }
};
