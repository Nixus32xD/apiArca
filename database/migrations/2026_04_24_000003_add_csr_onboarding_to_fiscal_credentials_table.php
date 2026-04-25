<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_credentials', function (Blueprint $table): void {
            $table->string('key_name', 120)->nullable();
            $table->longText('csr')->nullable();
            $table->string('status', 32)->default('active');

            $table->unique(['fiscal_company_id', 'key_name']);
            $table->index(['fiscal_company_id', 'status']);
        });

        DB::table('fiscal_credentials')
            ->where('active', false)
            ->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        Schema::table('fiscal_credentials', function (Blueprint $table): void {
            $table->dropUnique(['fiscal_company_id', 'key_name']);
            $table->dropIndex(['fiscal_company_id', 'status']);
            $table->dropColumn(['key_name', 'csr', 'status']);
        });
    }
};
