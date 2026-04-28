<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->company?->external_business_id,
            'company' => [
                'id' => $this->company?->id,
                'cuit' => $this->company?->cuit,
                'legal_name' => $this->company?->legal_name,
                'environment' => $this->company?->environment,
            ],
            'origin' => [
                'type' => $this->origin_type,
                'id' => $this->origin_id,
            ],
            'document_type' => $this->document_type,
            'point_of_sale' => $this->point_of_sale,
            'cbte_type' => $this->voucher_type,
            'concept' => $this->concept,
            'number' => $this->document_number,
            'status' => $this->status,
            'fiscal_status' => $this->fiscal_status,
            'authorization_type' => $this->authorization_type,
            'authorization_code' => $this->authorization_code,
            'authorization_expires_at' => $this->authorization_expires_at?->toDateString(),
            'cae' => $this->cae,
            'cae_expires_at' => $this->cae_expires_at?->toDateString(),
            'caea' => [
                'period' => $this->caea_period,
                'order' => $this->caea_order,
                'from' => $this->caea_from,
                'to' => $this->caea_to,
                'due_date' => $this->caea_due_date?->toDateString(),
                'report_deadline' => $this->caea_report_deadline?->toDateString(),
            ],
            'idempotency_key' => $this->idempotency_key,
            'error' => [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ],
            'observations' => $this->observations,
            'metadata' => $this->metadata,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn ($attempt) => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'operation' => $attempt->operation,
                'status' => $attempt->status,
                'error_code' => $attempt->error_code,
                'error_message' => $attempt->error_message,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'finished_at' => $attempt->finished_at?->toIso8601String(),
                'duration_ms' => $attempt->duration_ms,
                'trace_id' => $attempt->trace_id,
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'message' => $event->message,
                'data' => $event->data,
                'created_at' => $event->created_at?->toIso8601String(),
            ])),
        ];
    }
}
