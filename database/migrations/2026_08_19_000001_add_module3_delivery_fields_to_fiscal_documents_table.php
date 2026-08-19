<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('fiscal_documents', 'pdf_storage_key')) {
                $table->string('pdf_storage_key')->nullable()->after('processed_at');
            }

            if (! Schema::hasColumn('fiscal_documents', 'pdf_sha256')) {
                $table->string('pdf_sha256', 64)->nullable()->after('pdf_storage_key');
            }

            if (! Schema::hasColumn('fiscal_documents', 'pdf_generated_at')) {
                $table->timestamp('pdf_generated_at')->nullable()->after('pdf_sha256');
            }

            if (! Schema::hasColumn('fiscal_documents', 'email_to')) {
                $table->string('email_to')->nullable()->after('pdf_generated_at');
            }

            if (! Schema::hasColumn('fiscal_documents', 'email_status')) {
                $table->string('email_status', 20)->nullable()->after('email_to');
            }

            if (! Schema::hasColumn('fiscal_documents', 'email_attempts')) {
                $table->unsignedInteger('email_attempts')->default(0)->after('email_status');
            }

            if (! Schema::hasColumn('fiscal_documents', 'email_sent_at')) {
                $table->timestamp('email_sent_at')->nullable()->after('email_attempts');
            }

            if (! Schema::hasColumn('fiscal_documents', 'email_last_error')) {
                $table->text('email_last_error')->nullable()->after('email_sent_at');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            'pdf_storage_key',
            'pdf_sha256',
            'pdf_generated_at',
            'email_to',
            'email_status',
            'email_attempts',
            'email_sent_at',
            'email_last_error',
        ], fn (string $column): bool => Schema::hasColumn('fiscal_documents', $column)));

        if ($columns !== []) {
            Schema::table('fiscal_documents', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
