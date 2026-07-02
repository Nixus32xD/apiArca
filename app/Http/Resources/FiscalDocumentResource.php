<?php

namespace App\Http\Resources;

use App\Services\Fiscal\FiscalVoucherResolver;
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
                'fiscal_condition' => $this->company?->fiscal_condition,
                'environment' => $this->company?->environment,
            ],
            'origin' => [
                'type' => $this->origin_type,
                'id' => $this->origin_id,
            ],
            'document_type' => $this->document_type,
            'document_kind' => data_get($this->normalized_payload, 'document_kind')
                ?? FiscalVoucherResolver::documentKindForVoucher((int) $this->voucher_type),
            'point_of_sale' => $this->point_of_sale,
            'cbte_type' => $this->voucher_type,
            'concept' => $this->concept,
            'number' => $this->document_number,
            'voucher_date' => $this->voucher_date?->toDateString()
                ?? $this->formatAfipDate(data_get($this->normalized_payload, 'voucher_date')),
            'customer' => $this->customerPayload(),
            'amounts' => $this->amountsPayload(),
            'payment' => $this->paymentPayload(),
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

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(): array
    {
        $customer = data_get($this->normalized_payload, 'customer', []);

        return is_array($customer) && $customer !== []
            ? $customer
            : [
                'doc_type' => $this->customer_doc_type,
                'doc_number' => $this->customer_doc_number ? (int) $this->customer_doc_number : null,
                'document_number' => $this->customer_doc_number,
                'name' => $this->customer_name,
                'iva_condition' => $this->customer_iva_condition,
                'tax_condition_id' => $this->customer_tax_condition_id,
            ];
    }

    /**
     * @return array<string, mixed>
     */
    private function amountsPayload(): array
    {
        $amounts = data_get($this->normalized_payload, 'amounts', []);
        $ivaItems = $this->whenLoaded(
            'ivaItems',
            fn () => $this->ivaItems->map(fn ($item) => [
                'id' => $item->iva_id,
                'rate' => $item->rate,
                'base_imp' => $item->base_imp,
                'importe' => $item->importe,
            ])->values()->all(),
            data_get($amounts, 'iva_items', []),
        );

        return [
            'imp_total' => $this->imp_total ?? data_get($amounts, 'imp_total'),
            'imp_neto' => $this->imp_neto ?? data_get($amounts, 'imp_neto'),
            'imp_iva' => $this->imp_iva ?? data_get($amounts, 'imp_iva'),
            'imp_trib' => $this->imp_trib ?? data_get($amounts, 'imp_trib'),
            'imp_op_ex' => $this->imp_op_ex ?? data_get($amounts, 'imp_op_ex'),
            'imp_tot_conc' => $this->imp_tot_conc ?? data_get($amounts, 'imp_tot_conc'),
            'iva_items' => $ivaItems,
            'trib_items' => data_get($amounts, 'trib_items', []),
            'sign' => FiscalVoucherResolver::signForVoucher((int) $this->voucher_type),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(): array
    {
        $payment = data_get($this->normalized_payload, 'payment', []);

        return [
            'method' => $this->payment_method ?? data_get($payment, 'method'),
            'amount' => $this->payment_amount ?? data_get($payment, 'amount'),
            'reference' => $this->payment_reference ?? data_get($payment, 'reference'),
            'paid_at' => $this->paid_at?->toIso8601String() ?? data_get($payment, 'paid_at'),
        ];
    }

    private function formatAfipDate(mixed $value): ?string
    {
        if (! is_scalar($value) || ! preg_match('/^\d{8}$/', (string) $value)) {
            return null;
        }

        return substr((string) $value, 0, 4).'-'.substr((string) $value, 4, 2).'-'.substr((string) $value, 6, 2);
    }
}
