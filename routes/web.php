<?php

use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/fiscal/companies/{company}/status', function (string $company) {
    abort_unless(app()->environment('local'), 404);

    $fiscalCompany = FiscalCompany::query()
        ->where('external_business_id', $company)
        ->when(is_numeric($company), fn ($query) => $query->orWhereKey((int) $company))
        ->with(['activeCredential', 'accessTickets'])
        ->firstOrFail();

    $credential = $fiscalCompany->activeCredential ?? $fiscalCompany->credentials->sortByDesc('id')->first();
    $ticket = $fiscalCompany->accessTickets
        ->firstWhere('service', (string) config('fiscal.wsaa.service', 'wsfe'));

    return response()->json([
        'company' => [
            'id' => $fiscalCompany->id,
            'business_id' => $fiscalCompany->external_business_id,
            'cuit' => $fiscalCompany->cuit,
            'legal_name' => $fiscalCompany->legal_name,
            'environment' => $fiscalCompany->environment,
            'enabled' => $fiscalCompany->enabled,
            'default_point_of_sale' => $fiscalCompany->default_point_of_sale,
            'default_voucher_type' => $fiscalCompany->default_voucher_type,
        ],
        'credential' => [
            'configured' => (bool) $credential,
            'id' => $credential?->id,
            'key_name' => $credential?->key_name,
            'status' => $credential?->status,
            'active' => (bool) $credential?->active,
            'certificate_expires_at' => $credential?->certificate_expires_at?->toIso8601String(),
        ],
        'access_ticket' => [
            'configured' => (bool) $ticket,
            'valid' => (bool) ($ticket && $ticket->expiration_time->isFuture()),
            'generation_time' => $ticket?->generation_time?->toIso8601String(),
            'expiration_time' => $ticket?->expiration_time?->toIso8601String(),
            'last_used_at' => $ticket?->last_used_at?->toIso8601String(),
            'reused_count' => $ticket?->reused_count,
            'metadata' => $ticket?->metadata,
        ],
    ]);
});

Route::get('/fiscal/documents', function () {
    abort_unless(app()->environment('local'), 404);

    $documents = FiscalDocument::query()
        ->with('company')
        ->latest()
        ->limit(100)
        ->get()
        ->map(fn (FiscalDocument $document) => [
            'id' => $document->id,
            'business_id' => $document->company?->external_business_id,
            'cuit' => $document->company?->cuit,
            'origin_type' => $document->origin_type,
            'origin_id' => $document->origin_id,
            'document_type' => $document->document_type,
            'point_of_sale' => $document->point_of_sale,
            'cbte_type' => $document->voucher_type,
            'number' => $document->document_number,
            'status' => $document->status,
            'cae' => $document->cae,
            'cae_expires_at' => $document->cae_expires_at?->toDateString(),
            'error_code' => $document->error_code,
            'error_message' => $document->error_message,
            'idempotency_key' => $document->idempotency_key,
            'processed_at' => $document->processed_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
            'detail_url' => url("/fiscal/documents/{$document->id}"),
        ]);

    return response()->json(['data' => $documents]);
});

Route::get('/fiscal/documents/{document}', function (FiscalDocument $document) {
    abort_unless(app()->environment('local'), 404);

    $document->load(['company', 'attempts', 'events']);

    return response()->json([
        'data' => [
            'id' => $document->id,
            'business_id' => $document->company?->external_business_id,
            'cuit' => $document->company?->cuit,
            'origin' => [
                'type' => $document->origin_type,
                'id' => $document->origin_id,
            ],
            'document_type' => $document->document_type,
            'point_of_sale' => $document->point_of_sale,
            'cbte_type' => $document->voucher_type,
            'number' => $document->document_number,
            'status' => $document->status,
            'cae' => $document->cae,
            'cae_expires_at' => $document->cae_expires_at?->toDateString(),
            'error' => [
                'code' => $document->error_code,
                'message' => $document->error_message,
            ],
            'observations' => $document->observations,
            'idempotency_key' => $document->idempotency_key,
            'metadata' => $document->metadata,
            'request_payload' => $document->request_payload,
            'response_payload' => $document->response_payload,
            'attempts' => $document->attempts->map(fn ($attempt) => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'operation' => $attempt->operation,
                'status' => $attempt->status,
                'error_code' => $attempt->error_code,
                'error_message' => $attempt->error_message,
                'duration_ms' => $attempt->duration_ms,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'finished_at' => $attempt->finished_at?->toIso8601String(),
            ]),
            'events' => $document->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'message' => $event->message,
                'data' => $event->data,
                'created_at' => $event->created_at?->toIso8601String(),
            ]),
            'processed_at' => $document->processed_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ],
    ]);
});
