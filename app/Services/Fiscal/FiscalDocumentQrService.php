<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalDocument;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class FiscalDocumentQrService
{
    /**
     * @return array<string, int|float|string>
     */
    public function payload(FiscalDocument $document): array
    {
        $this->assertAuthorized($document);

        $payload = [
            'ver' => 1,
            'fecha' => $document->voucher_date?->toDateString(),
            'cuit' => (int) $document->company->cuit,
            'ptoVta' => (int) $document->point_of_sale,
            'tipoCmp' => (int) $document->voucher_type,
            'nroCmp' => (int) $document->document_number,
            'importe' => $this->numeric((float) $document->imp_total, 2),
            'moneda' => (string) (data_get($document->normalized_payload, 'currency') ?: config('fiscal.defaults.currency', 'PES')),
            'ctz' => $this->numeric((float) (data_get($document->normalized_payload, 'currency_rate') ?: config('fiscal.defaults.currency_rate', 1)), 6),
            'tipoCodAut' => strtoupper((string) $document->authorization_type) === 'CAEA' ? 'A' : 'E',
            'codAut' => (int) $document->authorization_code,
        ];

        if ($document->customer_doc_type !== null && $document->customer_doc_number !== null) {
            $payload['tipoDocRec'] = (int) $document->customer_doc_type;
            $payload['nroDocRec'] = (int) $document->customer_doc_number;
        }

        return $payload;
    }

    public function url(FiscalDocument $document): string
    {
        $json = json_encode($this->payload($document), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($json)) {
            throw new FiscalException('Could not encode fiscal QR payload.', 500, 'qr_payload_encode_failed');
        }

        $baseUrl = rtrim((string) config('fiscal.documents.qr_url', 'https://www.arca.gob.ar/fe/qr/'), '/').'/';

        return $baseUrl.'?p='.base64_encode($json);
    }

    public function svg(FiscalDocument $document): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle((int) config('fiscal.documents.qr_size', 180), 4),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($this->url($document));
    }

    private function assertAuthorized(FiscalDocument $document): void
    {
        $document->loadMissing('company');

        if ($document->status !== 'authorized' || ! $document->document_number || ! $document->authorization_code || ! $document->voucher_date) {
            throw new FiscalException('Only authorized fiscal documents can expose PDF or QR.', 409, 'document_not_authorized');
        }
    }

    private function numeric(float $value, int $scale): int|float
    {
        $rounded = round($value, $scale);

        if (abs($rounded - round($rounded)) < 0.000001) {
            return (int) round($rounded);
        }

        return $rounded;
    }
}
