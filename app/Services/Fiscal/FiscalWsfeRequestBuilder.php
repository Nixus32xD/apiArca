<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocument;
use Illuminate\Support\Carbon;

class FiscalWsfeRequestBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function cae(FiscalDocument $document): array
    {
        return $this->withDetail($document, 'FECAEDetRequest', $this->detail($document));
    }

    /**
     * @return array<string, mixed>
     */
    public function caea(FiscalDocument $document, string $caea): array
    {
        return $this->withDetail($document, 'FECAEADetRequest', array_merge(
            $this->detail($document),
            ['CAEA' => $caea],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function withDetail(FiscalDocument $document, string $detailNode, array $detail): array
    {
        return [
            'FeCabReq' => [
                'CantReg' => 1,
                'PtoVta' => $document->point_of_sale,
                'CbteTipo' => $document->voucher_type,
            ],
            'FeDetReq' => [
                $detailNode => [$detail],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(FiscalDocument $document): array
    {
        $payload = $document->normalized_payload;
        $amounts = $payload['amounts'];
        $customer = $payload['customer'];

        $detail = [
            'Concepto' => $payload['concept'],
            'DocTipo' => $customer['doc_type'],
            'DocNro' => $customer['doc_number'],
            'CbteDesde' => $document->document_number,
            'CbteHasta' => $document->document_number,
            'CbteFch' => $payload['voucher_date'],
            'ImpTotal' => $amounts['imp_total'],
            'ImpTotConc' => $amounts['imp_tot_conc'],
            'ImpNeto' => $amounts['imp_neto'],
            'ImpOpEx' => $amounts['imp_op_ex'],
            'ImpTrib' => $amounts['imp_trib'],
            'ImpIVA' => $amounts['imp_iva'],
            'MonId' => $payload['currency'],
            'MonCotiz' => $payload['currency_rate'],
            'CondicionIVAReceptorId' => $customer['tax_condition_id'],
        ];

        if ((int) $payload['concept'] !== 1) {
            $detail['FchServDesde'] = $payload['service_dates']['from'];
            $detail['FchServHasta'] = $payload['service_dates']['to'];
            $detail['FchVtoPago'] = $payload['service_dates']['payment_due_date'];
        }

        if ($amounts['iva_items'] !== []) {
            $detail['Iva'] = ['AlicIva' => $amounts['iva_items']];
        }

        if ($amounts['trib_items'] !== []) {
            $detail['Tributos'] = ['Tributo' => $amounts['trib_items']];
        }

        if ($payload['associated_vouchers'] !== []) {
            $detail['CbtesAsoc'] = ['CbteAsoc' => $payload['associated_vouchers']];
        }

        if ($payload['optional_fields'] !== []) {
            $detail['Opcionales'] = ['Opcional' => $payload['optional_fields']];
        }

        if ($payload['activities'] !== []) {
            $detail['Actividades'] = ['Actividad' => $payload['activities']];
        }

        return $detail;
    }

    public function afipDate(mixed $value): string
    {
        return Carbon::parse($value)->format('Ymd');
    }

    public function parseAfipDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::createFromFormat('Ymd', $value)->startOfDay();
    }
}
