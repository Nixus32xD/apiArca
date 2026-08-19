<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalDocument;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class FiscalDocumentPdfService
{
    public function __construct(
        private readonly FiscalDocumentQrService $qrService,
    ) {}

    public function render(FiscalDocument $document): string
    {
        $document->loadMissing(['company', 'ivaItems']);
        $this->assertAuthorized($document);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        Frame::$ID_COUNTER = 0;

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($document), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $date = $document->voucher_date?->format('Ymd') ?: '20000101';
        $pdfDate = "D:{$date}000000+00'00'";
        $dompdf->getCanvas()->add_info('CreationDate', $pdfDate);
        $dompdf->getCanvas()->add_info('ModDate', $pdfDate);
        $dompdf->getCanvas()->add_info('Title', $this->filename($document));

        return $this->deterministicPdf((string) $dompdf->output(['compress' => 0]), $document);
    }

    public function store(FiscalDocument $document): FiscalDocument
    {
        $pdf = $this->render($document);
        $key = $this->storageKey($document);

        Storage::disk($this->disk())->put($key, $pdf);

        $document->forceFill([
            'pdf_storage_key' => $key,
            'pdf_sha256' => hash('sha256', $pdf),
            'pdf_generated_at' => now(),
        ])->save();

        return $document->refresh();
    }

    public function filename(FiscalDocument $document): string
    {
        return sprintf(
            'comprobante-%s-%04d-%03d-%08d.pdf',
            $document->company?->cuit ?: 'sin-cuit',
            (int) $document->point_of_sale,
            (int) $document->voucher_type,
            (int) $document->document_number,
        );
    }

    public function storageKey(FiscalDocument $document): string
    {
        return sprintf(
            'fiscal-documents/%d/%d/%s',
            (int) $document->fiscal_company_id,
            (int) $document->id,
            $this->filename($document),
        );
    }

    public function disk(): string
    {
        return (string) config('fiscal.documents.disk', 'local');
    }

    private function html(FiscalDocument $document): string
    {
        $authorizationLabel = strtoupper((string) $document->authorization_type) === 'CAEA' ? 'CAEA' : 'CAE';
        $authorizationDue = $document->authorization_expires_at?->toDateString()
            ?? $document->cae_expires_at?->toDateString()
            ?? $document->caea_due_date?->toDateString();
        $qrSvg = base64_encode($this->qrService->svg($document));
        $qrUrl = $this->qrService->url($document);
        $ivaRows = $document->ivaItems
            ->map(fn ($item): string => '<tr><td>IVA '.$this->e((string) $item->rate).'%</td><td>'.$this->money($item->base_imp).'</td><td>'.$this->money($item->importe).'</td></tr>')
            ->implode('');
        $tribRows = collect(data_get($document->normalized_payload, 'amounts.trib_items', []))
            ->map(fn ($item): string => '<tr><td>'.$this->e((string) ($item['Desc'] ?? $item['desc'] ?? 'Tributo')).'</td><td>'.$this->money($item['BaseImp'] ?? $item['base_imp'] ?? 0).'</td><td>'.$this->money($item['Importe'] ?? $item['importe'] ?? 0).'</td></tr>')
            ->implode('');

        if ($ivaRows === '') {
            $ivaRows = '<tr><td colspan="3">Sin IVA discriminado</td></tr>';
        }

        if ($tribRows === '') {
            $tribRows = '<tr><td colspan="3">Sin tributos/percepciones</td></tr>';
        }

        return '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
body{font-family:Helvetica,Arial,sans-serif;color:#111;font-size:12px;margin:24px}
h1{font-size:20px;margin:0 0 10px}
h2{font-size:14px;margin:18px 0 8px;border-bottom:1px solid #bbb;padding-bottom:4px}
.header{display:table;width:100%;border-bottom:2px solid #111;padding-bottom:12px}
.header>div{display:table-cell;vertical-align:top}
.right{text-align:right}
.box{border:1px solid #bbb;padding:10px;margin-top:10px}
table{width:100%;border-collapse:collapse;margin-top:8px}
td,th{border:1px solid #ccc;padding:6px;text-align:left}
th{background:#eee}
.totals td{font-weight:bold}
.qr{width:180px;text-align:center}
.small{font-size:9px;word-break:break-all;color:#333}
</style>
</head>
<body>
<div class="header">
<div>
<h1>Comprobante fiscal autorizado</h1>
<div><strong>Emisor:</strong> '.$this->e((string) $document->company->legal_name).'</div>
<div><strong>CUIT:</strong> '.$this->e((string) $document->company->cuit).'</div>
<div><strong>Condicion fiscal:</strong> '.$this->e((string) $document->company->fiscal_condition).'</div>
</div>
<div class="right">
<div><strong>Tipo:</strong> '.$this->e((string) $document->document_type).' ('.$document->voucher_type.')</div>
<div><strong>Punto de venta:</strong> '.(int) $document->point_of_sale.'</div>
<div><strong>Numero:</strong> '.(int) $document->document_number.'</div>
<div><strong>Fecha:</strong> '.$this->e((string) $document->voucher_date?->toDateString()).'</div>
</div>
</div>
<h2>Receptor</h2>
<div class="box">
<div><strong>Nombre:</strong> '.$this->e((string) $document->customer_name).'</div>
<div><strong>Documento:</strong> '.$this->e((string) $document->customer_doc_type).' '.$this->e((string) $document->customer_doc_number).'</div>
<div><strong>Condicion IVA:</strong> '.$this->e((string) $document->customer_iva_condition).'</div>
</div>
<h2>Importes</h2>
<table>
<tr><th>Concepto</th><th>Importe</th></tr>
<tr><td>Neto gravado</td><td>'.$this->money($document->imp_neto).'</td></tr>
<tr><td>IVA</td><td>'.$this->money($document->imp_iva).'</td></tr>
<tr><td>Tributos / percepciones</td><td>'.$this->money($document->imp_trib).'</td></tr>
<tr><td>Exento</td><td>'.$this->money($document->imp_op_ex).'</td></tr>
<tr><td>No gravado</td><td>'.$this->money($document->imp_tot_conc).'</td></tr>
<tr class="totals"><td>Total</td><td>'.$this->money($document->imp_total).'</td></tr>
</table>
<h2>IVA</h2>
<table><tr><th>Alicuota</th><th>Base</th><th>Importe</th></tr>'.$ivaRows.'</table>
<h2>Tributos / Percepciones</h2>
<table><tr><th>Detalle</th><th>Base</th><th>Importe</th></tr>'.$tribRows.'</table>
<h2>Autorizacion</h2>
<table>
<tr><th>Tipo</th><th>Codigo</th><th>Vencimiento</th></tr>
<tr><td>'.$authorizationLabel.'</td><td>'.$this->e((string) $document->authorization_code).'</td><td>'.$this->e((string) $authorizationDue).'</td></tr>
</table>
<h2>QR ARCA</h2>
<table><tr><td class="qr"><img alt="QR ARCA" width="160" height="160" src="data:image/svg+xml;base64,'.$qrSvg.'"></td><td class="small">'.$this->e($qrUrl).'</td></tr></table>
</body>
</html>';
    }

    private function assertAuthorized(FiscalDocument $document): void
    {
        if ($document->status !== 'authorized' || ! $document->document_number || ! $document->authorization_code || ! $document->voucher_date) {
            throw new FiscalException('Only authorized fiscal documents can be rendered as PDF.', 409, 'document_not_authorized');
        }
    }

    private function money(mixed $value): string
    {
        return '$ '.number_format((float) $value, 2, ',', '.');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function deterministicPdf(string $pdf, FiscalDocument $document): string
    {
        $binaryMarker = '%'.chr(226).chr(227).chr(207).chr(211);
        $pdf = preg_replace('/^(%PDF-[^\r\n]+)\R%[^\r\n]*/', '$1'."\n".$binaryMarker, $pdf, 1) ?? $pdf;

        $id = md5(implode('|', [
            $document->fiscal_company_id,
            $document->id,
            $document->point_of_sale,
            $document->voucher_type,
            $document->document_number,
            $document->authorization_code,
        ]));

        return preg_replace('/\/ID\[<[0-9a-fA-F]+><[0-9a-fA-F]+>\]/', '/ID[<'.$id.'><'.$id.'>]', $pdf, 1) ?? $pdf;
    }
}
