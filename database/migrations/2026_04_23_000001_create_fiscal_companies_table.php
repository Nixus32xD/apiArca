<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('external_business_id')->unique();
            $table->string('cuit', 11);
            $table->string('legal_name');
            $table->string('environment', 20)->default('testing');
            $table->unsignedInteger('default_point_of_sale')->nullable();
            $table->unsignedInteger('default_voucher_type')->nullable();
            $table->boolean('enabled')->default(false);
            $table->json('onboarding_metadata')->nullable();
            $table->timestamps();

            $table->unique(['cuit', 'environment'], 'fiscal_companies_cuit_env_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_companies');
    }
};
