<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_companies', function (Blueprint $table): void {
            $table->string('fiscal_condition', 40)
                ->default('monotributo')
                ->after('legal_name');

            $table->index('fiscal_condition', 'fiscal_companies_condition_index');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_companies', function (Blueprint $table): void {
            $table->dropIndex('fiscal_companies_condition_index');
            $table->dropColumn('fiscal_condition');
        });
    }
};
