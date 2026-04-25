<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('fiscal_companies', 'fiscal_companies_cuit_unique')) {
            Schema::table('fiscal_companies', function (Blueprint $table): void {
                $table->dropUnique('fiscal_companies_cuit_unique');
            });
        }

        if (! Schema::hasIndex('fiscal_companies', 'fiscal_companies_cuit_env_unique')) {
            Schema::table('fiscal_companies', function (Blueprint $table): void {
                $table->unique(['cuit', 'environment'], 'fiscal_companies_cuit_env_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('fiscal_companies', 'fiscal_companies_cuit_env_unique')) {
            Schema::table('fiscal_companies', function (Blueprint $table): void {
                $table->dropUnique('fiscal_companies_cuit_env_unique');
            });
        }

        if (! Schema::hasIndex('fiscal_companies', 'fiscal_companies_cuit_unique')) {
            Schema::table('fiscal_companies', function (Blueprint $table): void {
                $table->unique('cuit', 'fiscal_companies_cuit_unique');
            });
        }
    }
};
