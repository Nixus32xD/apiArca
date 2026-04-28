<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('fiscal_documents', 'authorization_type')) {
                $table->string('authorization_type', 10)->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'authorization_code')) {
                $table->string('authorization_code')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'authorization_expires_at')) {
                $table->date('authorization_expires_at')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'caea_period')) {
                $table->string('caea_period', 6)->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'caea_order')) {
                $table->unsignedSmallInteger('caea_order')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'caea_from')) {
                $table->unsignedBigInteger('caea_from')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'caea_to')) {
                $table->unsignedBigInteger('caea_to')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'caea_due_date')) {
                $table->date('caea_due_date')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'caea_report_deadline')) {
                $table->date('caea_report_deadline')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'fiscal_status')) {
                $table->string('fiscal_status', 40)->nullable()->index();
            }

            if (! Schema::hasColumn('fiscal_documents', 'raw_request')) {
                $table->json('raw_request')->nullable();
            }

            if (! Schema::hasColumn('fiscal_documents', 'raw_response')) {
                $table->json('raw_response')->nullable();
            }
        });

        DB::table('fiscal_documents')
            ->orderBy('id')
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $document) {
                    DB::table('fiscal_documents')
                        ->where('id', $document->id)
                        ->update([
                            'authorization_type' => $document->authorization_type ?: ($document->cae ? 'CAE' : null),
                            'authorization_code' => $document->authorization_code ?: $document->cae,
                            'authorization_expires_at' => $document->authorization_expires_at ?: $document->cae_expires_at,
                            'fiscal_status' => $document->fiscal_status ?: $this->fiscalStatus($document->status ?? null),
                            'raw_request' => $document->raw_request ?: $document->request_payload,
                            'raw_response' => $document->raw_response ?: $document->response_payload,
                        ]);
                }
            });
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            'authorization_type',
            'authorization_code',
            'authorization_expires_at',
            'caea_period',
            'caea_order',
            'caea_from',
            'caea_to',
            'caea_due_date',
            'caea_report_deadline',
            'fiscal_status',
            'raw_request',
            'raw_response',
        ], fn (string $column): bool => Schema::hasColumn('fiscal_documents', $column)));

        if ($columns !== []) {
            Schema::table('fiscal_documents', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function fiscalStatus(?string $status): ?string
    {
        return match ($status) {
            'processing' => 'pending',
            'authorized' => 'authorized',
            'rejected' => 'rejected',
            'uncertain' => 'uncertain',
            'error' => 'failed',
            default => $status,
        };
    }
};
