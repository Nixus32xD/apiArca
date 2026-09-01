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
        $documentTitle = $this->documentTitle($document);
        $items = collect(data_get($document->normalized_payload, 'items', []));
        $itemRows = $items
            ->map(fn ($item): string => '<tr><td>'.$this->e((string) ($item['description'] ?? 'Concepto facturado')).'</td><td class="number">'.$this->e((string) ($item['quantity'] ?? 1)).'</td><td class="money">'.$this->money($item['total'] ?? $item['unit_price'] ?? $document->imp_total).'</td></tr>')
            ->implode('');
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

        if ($itemRows === '') {
            $itemRows = '<tr><td>Servicios / productos facturados</td><td class="number">1</td><td class="money">'.$this->money($document->imp_total).'</td></tr>';
        }

        return '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
@page{margin:26px 32px}
body{font-family:Helvetica,Arial,sans-serif;color:#1d2421;font-size:10px;line-height:1.35;margin:0}
table{width:100%;border-collapse:collapse}
.masthead{border-bottom:2px solid #263b33;margin-bottom:14px}
.masthead td{vertical-align:top;padding:0 0 12px}
.eyebrow{font-size:8px;letter-spacing:1.2px;color:#66736d;font-weight:bold;margin:0 0 3px}
h1{font-size:22px;line-height:1;margin:0;color:#263b33}
.issuer{font-size:10px;color:#44514b;margin:7px 0 0}
.issuer strong{color:#1d2421}
.document-card{width:170px;border:1px solid #bcc8c1;background:#f4f7f5;padding:8px 10px;text-align:right}
.document-card .label{font-size:8px;letter-spacing:.8px;text-transform:uppercase;color:#66736d;font-weight:bold}
.document-card .number{font-size:17px;line-height:1.1;color:#263b33;font-weight:bold;margin:2px 0 5px}
.section{margin-top:12px}
.section-title{font-size:9px;letter-spacing:1px;text-transform:uppercase;font-weight:bold;color:#41554a;border-bottom:1px solid #cdd7d1;padding-bottom:4px;margin-bottom:6px}
.parties td{width:50%;vertical-align:top;border:1px solid #d6ded9;padding:8px 10px}
.parties td+td{border-left:0}
.caption{font-size:8px;letter-spacing:.7px;text-transform:uppercase;color:#66736d;font-weight:bold;margin-bottom:3px}
.name{font-size:12px;font-weight:bold;color:#263b33;margin-bottom:4px}
.muted{color:#66736d}
.lines{border:1px solid #d6ded9}
.lines th{background:#edf2ee;color:#41554a;font-size:8px;letter-spacing:.6px;text-transform:uppercase;text-align:left;padding:6px 8px}
.lines td{border-top:1px solid #d6ded9;padding:6px 8px}
.number{text-align:center}
.money{text-align:right;white-space:nowrap}
.total td{font-size:12px;font-weight:bold;color:#263b33;background:#f4f7f5}
.tax-grid td{width:50%;vertical-align:top}
.tax-grid td+td{padding-left:9px}
.tax-grid .lines td,.tax-grid .lines th{padding:5px 7px}
.authorization{border:1px solid #9bb1a3;background:#f4f7f5;padding:8px 10px}
.authorization td{vertical-align:top}
.authorization .code{font-size:13px;color:#263b33;font-weight:bold;letter-spacing:.4px}
.qr-panel{border-top:1px solid #cdd7d1;margin-top:13px;padding-top:9px}
.qr-panel td{vertical-align:middle}
.qr-image{width:112px;text-align:center}
.qr-copy{padding-left:12px;color:#536159;font-size:9px}
.qr-copy strong{display:block;font-size:11px;color:#263b33;margin-bottom:3px}
.footer{margin-top:9px;padding-top:5px;border-top:1px solid #e0e6e2;color:#758178;font-size:8px;text-align:center}
</style>
</head>
<body>
<table class="masthead"><tr><td>
<p class="eyebrow">COMPROBANTE ELECTRÓNICO AUTORIZADO</p>
<h1>'.$this->e($documentTitle).'</h1>
<p class="issuer"><strong>'.$this->e((string) $document->company->legal_name).'</strong><br>CUIT '.$this->e((string) $document->company->cuit).' · '.$this->e($this->fiscalConditionLabel((string) $document->company->fiscal_condition)).'</p>
</td><td style="width:180px"><div class="document-card"><div class="label">Comprobante</div><div class="number">'.str_pad((string) $document->point_of_sale, 4, '0', STR_PAD_LEFT).'-'.str_pad((string) $document->document_number, 8, '0', STR_PAD_LEFT).'</div><div><strong>Fecha:</strong> '.$this->e((string) $document->voucher_date?->format('d/m/Y')).'</div></div></td></tr></table>
<div class="section"><div class="section-title">Datos de las partes</div><table class="parties"><tr><td><div class="caption">Emisor</div><div class="name">'.$this->e((string) $document->company->legal_name).'</div><div>CUIT '.$this->e((string) $document->company->cuit).'</div><div class="muted">'.$this->e($this->fiscalConditionLabel((string) $document->company->fiscal_condition)).'</div></td><td><div class="caption">Receptor</div><div class="name">'.$this->e((string) $document->customer_name).'</div><div>Documento '.$this->e((string) $document->customer_doc_type).' '.$this->e((string) $document->customer_doc_number).'</div><div class="muted">'.$this->e($this->customerConditionLabel((string) $document->customer_iva_condition)).'</div></td></tr></table></div>
<div class="section"><div class="section-title">Detalle</div><table class="lines"><tr><th>Descripción</th><th style="width:55px;text-align:center">Cant.</th><th style="width:125px;text-align:right">Importe</th></tr>'.$itemRows.'</table></div>
<div class="section"><div class="section-title">Importes</div><table class="lines"><tr><td>Neto gravado</td><td class="money">'.$this->money($document->imp_neto).'</td></tr><tr><td>IVA</td><td class="money">'.$this->money($document->imp_iva).'</td></tr><tr><td>Tributos / percepciones</td><td class="money">'.$this->money($document->imp_trib).'</td></tr><tr><td>Exento y no gravado</td><td class="money">'.$this->money((float) $document->imp_op_ex + (float) $document->imp_tot_conc).'</td></tr><tr class="total"><td>Total</td><td class="money">'.$this->money($document->imp_total).'</td></tr></table></div>
<div class="section"><table class="tax-grid"><tr><td><div class="section-title">IVA</div><table class="lines"><tr><th>Alicuota</th><th>Base</th><th>Importe</th></tr>'.$ivaRows.'</table></td><td><div class="section-title">Tributos</div><table class="lines"><tr><th>Detalle</th><th>Base</th><th>Importe</th></tr>'.$tribRows.'</table></td></tr></table></div>
<div class="section"><div class="section-title">Autorización ARCA</div><div class="authorization"><table><tr><td style="width:17%"><div class="caption">Tipo</div><strong>'.$authorizationLabel.'</strong></td><td style="width:48%"><div class="caption">Código de autorización</div><div class="code">'.$this->e((string) $document->authorization_code).'</div></td><td><div class="caption">Vencimiento</div><strong>'.$this->e((string) $authorizationDue).'</strong></td></tr></table></div></div>
<div class="qr-panel"><table><tr><td class="qr-image"><img alt="QR ARCA" width="112" height="112" src="data:image/svg+xml;base64,'.$qrSvg.'"></td><td class="qr-copy"><strong>Validación fiscal ARCA</strong>Escaneá el código QR para consultar los datos oficiales del comprobante en ARCA.</td></tr></table></div>
<div class="footer">Comprobante generado electrónicamente · Conservá este documento junto con su código de autorización.</div>
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

    private function documentTitle(FiscalDocument $document): string
    {
        return match ((int) $document->voucher_type) {
            1 => 'Factura A',
            2 => 'Nota de débito A',
            3 => 'Nota de crédito A',
            6 => 'Factura B',
            7 => 'Nota de débito B',
            8 => 'Nota de crédito B',
            11 => 'Factura C',
            12 => 'Nota de débito C',
            13 => 'Nota de crédito C',
            default => ucwords(str_replace('_', ' ', (string) $document->document_type)),
        };
    }

    private function fiscalConditionLabel(string $condition): string
    {
        return match ($condition) {
            'monotributo' => 'Monotributista',
            'responsable_inscripto' => 'Responsable inscripto',
            'exento' => 'Exento',
            default => ucfirst(str_replace('_', ' ', $condition)),
        };
    }

    private function customerConditionLabel(string $condition): string
    {
        return match ($condition) {
            'consumidor_final' => 'Consumidor final',
            'monotributo' => 'Monotributista',
            'responsable_inscripto' => 'Responsable inscripto',
            'exento' => 'Exento',
            default => ucfirst(str_replace('_', ' ', $condition)),
        };
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
