<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Http\Controllers\Controller;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Models\FiscalPurchase;
use App\Services\Fiscal\FiscalIvaBookService;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class FiscalAdminController extends Controller
{
    public function __construct(
        private readonly FiscalIvaBookService $ivaBookService,
    ) {}

    public function __invoke(Request $request): HttpResponse
    {
        $this->authorizeAdmin($request);

        $companies = FiscalCompany::query()
            ->orderBy('legal_name')
            ->get();

        $selectedCompany = $this->selectedCompany($request) ?? $companies->first();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $sales = null;
        $purchases = null;
        $dashboard = null;
        $dateFrom = is_scalar($dateFrom) ? (string) $dateFrom : '';
        $dateTo = is_scalar($dateTo) ? (string) $dateTo : '';

        if ($selectedCompany) {
            $sales = $this->ivaBookService->sales(
                $selectedCompany,
                $dateFrom !== '' ? $dateFrom : null,
                $dateTo !== '' ? $dateTo : null,
            );
            $purchases = $this->ivaBookService->purchases(
                $selectedCompany,
                $dateFrom !== '' ? $dateFrom : null,
                $dateTo !== '' ? $dateTo : null,
            );
            $dashboard = $this->dashboard($selectedCompany, $dateFrom, $dateTo, $sales, $purchases);
        }

        return response($this->renderHtml(
            $companies,
            $selectedCompany,
            $dateFrom,
            $dateTo,
            $sales,
            $purchases,
            $dashboard,
            is_scalar($request->query('admin_token')) ? (string) $request->query('admin_token') : '',
        ));
    }

    private function authorizeAdmin(Request $request): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        abort_unless((bool) config('fiscal.admin.enabled', false), 404);

        $configuredToken = (string) config('fiscal.admin.token', '');

        abort_if($configuredToken === '', SymfonyResponse::HTTP_SERVICE_UNAVAILABLE, 'Fiscal admin token is not configured.');

        $providedToken = $request->bearerToken()
            ?: $request->header('X-Admin-Token')
            ?: $request->query('admin_token');

        abort_unless(is_string($providedToken) && hash_equals($configuredToken, $providedToken), 403);
    }

    private function selectedCompany(Request $request): ?FiscalCompany
    {
        $company = $request->query('company');

        if (! is_scalar($company) || (string) $company === '') {
            return null;
        }

        return FiscalCompany::query()
            ->where('external_business_id', (string) $company)
            ->when(is_numeric($company), fn ($query) => $query->orWhereKey((int) $company))
            ->first();
    }

    /**
     * @param  Collection<int, FiscalCompany>  $companies
     * @param  array<string, mixed>|null  $sales
     * @param  array<string, mixed>|null  $purchases
     * @param  array<string, mixed>|null  $dashboard
     */
    private function renderHtml(Collection $companies, ?FiscalCompany $selectedCompany, string $dateFrom, string $dateTo, ?array $sales, ?array $purchases, ?array $dashboard, string $adminToken): string
    {
        $companyOptions = $companies->map(function (FiscalCompany $company) use ($selectedCompany): string {
            $selected = $selectedCompany?->id === $company->id ? ' selected' : '';

            return '<option value="'.e($company->external_business_id).'"'.$selected.'>'.e($company->legal_name.' - '.$company->external_business_id).'</option>';
        })->implode('');

        $hiddenToken = $adminToken !== ''
            ? '<input type="hidden" name="admin_token" value="'.e($adminToken).'">'
            : '';

        $body = $selectedCompany
            ? $this->dashboardBody($selectedCompany, $dateFrom, $dateTo, $sales, $purchases, $dashboard ?? [], $adminToken)
            : '<div class="empty">No hay empresas fiscales cargadas.</div>';

        $quickRanges = $selectedCompany
            ? $this->quickRangeLinks($selectedCompany, $adminToken)
            : '';

        return '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fiscal admin</title>
<style>
:root{color-scheme:light;--bg:#f5f7fb;--panel:#fff;--ink:#172033;--muted:#667085;--line:#dce3ee;--line-soft:#edf1f7;--brand:#0f5f78;--brand-2:#00a099;--good:#067647;--warn:#b54708;--bad:#b42318;--info:#175cd3;--shadow:0 10px 28px rgba(15,35,55,.08);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink)}
header{background:linear-gradient(135deg,#0b2336 0%,#0f5f78 58%,#00a099 100%);color:white;padding:22px 28px 26px}
main{padding:22px 28px 44px;max-width:1500px;margin:0 auto}
h1{font-size:24px;margin:0 0 5px;letter-spacing:0}
h2{font-size:17px;margin:28px 0 12px}
h3{font-size:14px;margin:0 0 10px}
a{color:inherit}
.topbar{max-width:1500px;margin:0 auto}
.muted{color:var(--muted)}
header .muted{color:#d7edf1}
form{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-top:16px}
label{display:grid;gap:6px;font-size:12px;color:#d7edf1}
select,input{border:1px solid rgba(255,255,255,.35);border-radius:8px;font:inherit;padding:9px 10px;min-width:180px;background:white;color:#172033}
button,.btn{border:0;border-radius:8px;background:#0f5f78;color:white;font:inherit;text-decoration:none;padding:10px 14px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;min-height:38px}
header button{background:#fff;color:#0f5f78;font-weight:700}
.btn.secondary{background:#eef7f7;color:#0f5f78;border:1px solid #cce8e7}
.btn.ghost{background:rgba(255,255,255,.13);color:white;border:1px solid rgba(255,255,255,.28)}
.quick{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.hero{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,.8fr);gap:16px;margin-bottom:18px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow)}
.panel.pad{padding:16px}
.company-title{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:10px}
.company-title strong{font-size:20px}
.badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:700;border:1px solid transparent}
.badge.good{background:#ecfdf3;color:var(--good);border-color:#abefc6}
.badge.warn{background:#fffaeb;color:var(--warn);border-color:#fedf89}
.badge.bad{background:#fef3f2;color:var(--bad);border-color:#fecdca}
.badge.info{background:#eff8ff;color:var(--info);border-color:#b2ddff}
.badge.neutral{background:#f2f4f7;color:#344054;border-color:#d0d5dd}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}
.fact{border:1px solid var(--line-soft);border-radius:10px;padding:10px;background:#fbfcfe}
.fact span,.metric span{display:block;color:var(--muted);font-size:12px}
.fact strong,.metric strong{display:block;font-size:14px;margin-top:4px}
.alerts{display:grid;gap:8px}
.alert{border:1px solid var(--line);border-left:4px solid var(--brand-2);border-radius:10px;padding:10px 12px;background:white;font-size:13px}
.alert.warn{border-left-color:var(--warn)}
.alert.bad{border-left-color:var(--bad)}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:12px;margin:12px 0 18px}
.metric{background:white;border:1px solid var(--line);border-radius:12px;padding:14px;box-shadow:var(--shadow)}
.metric strong{font-size:21px;font-variant-numeric:tabular-nums}
.metric small{display:block;color:var(--muted);margin-top:4px}
.grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0 0 10px}
.toolbar input{border:1px solid #cbd5e1;min-width:240px}
.endpoint-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin-top:10px}
.endpoint{border:1px solid var(--line-soft);border-radius:10px;padding:10px;background:#fbfcfe}
.endpoint code{display:block;font:12px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#344054;word-break:break-all;margin:7px 0}
.table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:12px;background:white;box-shadow:var(--shadow)}
table{width:100%;border-collapse:collapse;min-width:1040px}
th,td{border-bottom:1px solid var(--line-soft);padding:10px 11px;text-align:left;font-size:13px;white-space:nowrap;vertical-align:top}
th{background:#f8fafc;color:#344054;font-weight:750;position:sticky;top:0;z-index:1}
tr:last-child td{border-bottom:0}
tbody tr:hover{background:#fbfdff}
.num{text-align:right;font-variant-numeric:tabular-nums}
.stack{display:grid;gap:4px}
.tiny{font-size:12px;color:var(--muted)}
.iva-tags{display:flex;flex-wrap:wrap;gap:5px;max-width:280px}
.iva-tags span{background:#eef7f7;color:#0f5f78;border:1px solid #cce8e7;border-radius:999px;padding:3px 7px;font-size:12px}
.empty{background:white;border:1px solid var(--line);border-radius:12px;padding:18px;color:var(--muted)}
.section-head{display:flex;justify-content:space-between;gap:12px;align-items:end;margin-top:24px}
.section-head p{margin:3px 0 0;color:var(--muted);font-size:13px}
@media (max-width:900px){header,main{padding-left:16px;padding-right:16px}.hero,.grid-2{grid-template-columns:1fr}.toolbar{align-items:flex-start;flex-direction:column}.toolbar input{width:100%;min-width:0}}
</style>
</head>
<body>
<header>
<div class="topbar">
<h1>Fiscal admin</h1>
<div class="muted">Panel operativo local para ventas, compras, IVA, comprobantes y estado de integracion.</div>
<form method="get" action="/api/admin/">'.$hiddenToken.'
<label>Empresa<select name="company">'.$companyOptions.'</select></label>
<label>Desde<input type="date" name="date_from" value="'.e($dateFrom).'"></label>
<label>Hasta<input type="date" name="date_to" value="'.e($dateTo).'"></label>
<button type="submit">Filtrar</button>
</form>
'.$quickRanges.'
</div>
</header>
<main>'.$body.'</main>
<script>
document.querySelectorAll("[data-filter-target]").forEach(function(input){
  input.addEventListener("input", function(){
    var table = document.querySelector(input.dataset.filterTarget);
    if (!table) return;
    var term = input.value.toLowerCase();
    table.querySelectorAll("tbody tr").forEach(function(row){
      row.style.display = row.textContent.toLowerCase().includes(term) ? "" : "none";
    });
  });
});
document.querySelectorAll("[data-copy]").forEach(function(button){
  button.addEventListener("click", function(){
    var value = button.getAttribute("data-copy");
    if (navigator.clipboard) navigator.clipboard.writeText(value);
    button.textContent = "Copiado";
    window.setTimeout(function(){ button.textContent = "Copiar URL"; }, 1200);
  });
});
</script>
</body>
</html>';
    }

    /**
     * @param  array<string, mixed>  $sales
     * @param  array<string, mixed>  $purchases
     * @return array<string, mixed>
     */
    private function dashboard(FiscalCompany $company, string $dateFrom, string $dateTo, array $sales, array $purchases): array
    {
        $salesRecords = collect($sales['records'] ?? []);
        $purchaseRecords = collect($purchases['records'] ?? []);
        $salesTotals = (array) ($sales['totals'] ?? []);
        $purchaseTotals = (array) ($purchases['totals'] ?? []);
        $ivaDebit = (float) ($salesTotals['imp_iva'] ?? 0);
        $ivaCredit = (float) ($purchaseTotals['imp_iva'] ?? 0);

        return [
            'company_status' => $this->companyStatus($company),
            'alerts' => $this->alerts($company, $ivaDebit, $ivaCredit, $dateFrom, $dateTo),
            'kpis' => [
                ['label' => 'Ventas total', 'value' => $salesTotals['imp_total'] ?? 0, 'hint' => $salesRecords->count().' comprobantes autorizados', 'format' => 'money'],
                ['label' => 'Compras total', 'value' => $purchaseTotals['imp_total'] ?? 0, 'hint' => $purchaseRecords->count().' comprobantes cargados', 'format' => 'money'],
                ['label' => 'IVA debito fiscal', 'value' => $ivaDebit, 'hint' => 'IVA ventas firmado por tipo de comprobante', 'format' => 'money'],
                ['label' => 'IVA credito fiscal', 'value' => $ivaCredit, 'hint' => 'IVA compras firmado por tipo de comprobante', 'format' => 'money'],
                ['label' => 'Saldo IVA estimado', 'value' => $ivaDebit - $ivaCredit, 'hint' => 'Debito menos credito del filtro actual', 'format' => 'money'],
                ['label' => 'Errores / rechazados', 'value' => $this->problemDocuments($company, $dateFrom, $dateTo)->count(), 'hint' => 'Documentos con estado error, rejected o uncertain', 'format' => 'number'],
            ],
            'aliquots' => $this->aliquotRows($salesTotals, $purchaseTotals),
            'sales_mix' => $this->mixRows($salesRecords),
            'purchase_mix' => $this->mixRows($purchaseRecords),
            'sales_payment_mix' => $this->paymentMix($company, $dateFrom, $dateTo, true),
            'purchase_payment_mix' => $this->paymentMix($company, $dateFrom, $dateTo, false),
            'recent_documents' => $this->recentDocuments($company, $dateFrom, $dateTo)->all(),
            'recent_purchases' => $this->recentPurchases($company, $dateFrom, $dateTo)->all(),
            'problem_documents' => $this->problemDocuments($company, $dateFrom, $dateTo)->take(8)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sales
     * @param  array<string, mixed>|null  $purchases
     * @param  array<string, mixed>  $dashboard
     */
    private function dashboardBody(FiscalCompany $company, string $dateFrom, string $dateTo, ?array $sales, ?array $purchases, array $dashboard, string $adminToken): string
    {
        return $this->companyPanel($company, $dashboard)
            .$this->endpointPanel($company, $dateFrom, $dateTo)
            .$this->kpiSection((array) ($dashboard['kpis'] ?? []))
            .$this->aliquotSection((array) ($dashboard['aliquots'] ?? []))
            .'<div class="grid-2">'
            .$this->mixSection('Comprobantes ventas', (array) ($dashboard['sales_mix'] ?? []), 'ventas-mix')
            .$this->mixSection('Comprobantes compras', (array) ($dashboard['purchase_mix'] ?? []), 'compras-mix')
            .'</div>'
            .'<div class="grid-2">'
            .$this->paymentSection('Medios de pago ventas', (array) ($dashboard['sales_payment_mix'] ?? []))
            .$this->paymentSection('Medios de pago compras', (array) ($dashboard['purchase_payment_mix'] ?? []))
            .'</div>'
            .$this->bookSection('IVA Ventas', $sales, 'Cliente', true, 'sales-book')
            .$this->bookSection('IVA Compras', $purchases, 'Proveedor', false, 'purchases-book')
            .'<div class="grid-2">'
            .$this->recentDocumentsSection((array) ($dashboard['recent_documents'] ?? []))
            .$this->recentPurchasesSection((array) ($dashboard['recent_purchases'] ?? []))
            .'</div>'
            .$this->problemSection((array) ($dashboard['problem_documents'] ?? []), $adminToken);
    }

    /**
     * @param  array<string, mixed>  $dashboard
     */
    private function companyPanel(FiscalCompany $company, array $dashboard): string
    {
        $status = (array) ($dashboard['company_status'] ?? []);
        $alerts = collect($dashboard['alerts'] ?? [])
            ->map(fn (array $alert): string => '<div class="alert '.e((string) ($alert['tone'] ?? 'info')).'">'.e((string) $alert['message']).'</div>')
            ->implode('');

        $alerts = $alerts !== '' ? $alerts : '<div class="alert">Sin alertas locales para el filtro actual.</div>';

        return '<section class="hero">'
            .'<div class="panel pad">'
            .'<div class="company-title"><strong>'.e($company->legal_name).'</strong>'
            .$this->badge($company->enabled ? 'Habilitada' : 'Deshabilitada', $company->enabled ? 'good' : 'bad')
            .$this->badge((string) $company->environment, $company->environment === 'production' ? 'warn' : 'info')
            .$this->badge((string) $company->fiscal_condition, $company->fiscal_condition === 'responsable_inscripto' ? 'good' : 'neutral')
            .'</div>'
            .'<div class="facts">'
            .$this->fact('Business ID', $company->external_business_id)
            .$this->fact('CUIT', $company->cuit)
            .$this->fact('Punto venta default', $company->default_point_of_sale ?: '-')
            .$this->fact('Cbte default', $company->default_voucher_type ?: '-')
            .$this->fact('Credencial activa', $status['credential'] ?? 'No')
            .$this->fact('Ticket WSAA', $status['ticket'] ?? 'No disponible')
            .'</div></div>'
            .'<div class="panel pad"><h3>Alertas operativas</h3><div class="alerts">'.$alerts.'</div></div>'
            .'</section>';
    }

    private function endpointPanel(FiscalCompany $company, string $dateFrom, string $dateTo): string
    {
        $query = array_filter([
            'business_id' => $company->external_business_id,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to' => $dateTo !== '' ? $dateTo : null,
        ], fn ($value): bool => $value !== null && $value !== '');

        $endpoints = [
            'Libro IVA Ventas' => '/api/fiscal/documents/iva-sales?'.http_build_query($query),
            'Libro IVA Compras' => '/api/fiscal/purchases/iva-book?'.http_build_query($query),
            'Compras cargadas' => '/api/fiscal/purchases?'.http_build_query($query),
            'Estado fiscal SaaS' => '/api/fiscal/companies/'.$company->external_business_id.'/status',
            'Diagnostico ARCA' => '/api/fiscal/companies/'.$company->external_business_id.'/diagnostics',
        ];

        $cards = collect($endpoints)->map(function (string $url, string $label): string {
            return '<div class="endpoint"><strong>'.e($label).'</strong><code>'.e($url).'</code><button type="button" class="btn secondary" data-copy="'.e($url).'">Copiar URL</button></div>';
        })->implode('');

        return '<section class="panel pad"><div class="section-head" style="margin-top:0"><div><h2 style="margin-top:0">Accesos API</h2><p>URLs armadas con la empresa y el periodo filtrado. Los endpoints fiscales siguen requiriendo token de cliente.</p></div></div><div class="endpoint-grid">'.$cards.'</div></section>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $kpis
     */
    private function kpiSection(array $kpis): string
    {
        $cards = collect($kpis)->map(function (array $metric): string {
            return $this->metric(
                (string) $metric['label'],
                $metric['value'] ?? 0,
                (string) ($metric['format'] ?? 'money'),
                (string) ($metric['hint'] ?? ''),
            );
        })->implode('');

        return '<section><div class="section-head"><div><h2>Resumen del periodo</h2><p>Totales firmados: las notas de credito descuentan y las notas de debito suman.</p></div></div><div class="summary">'.$cards.'</div></section>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function aliquotSection(array $rows): string
    {
        if ($rows === []) {
            return '<section><div class="section-head"><div><h2>IVA por alicuota</h2><p>Sin IVA discriminado en el periodo.</p></div></div><div class="empty">No hay alicuotas para mostrar.</div></section>';
        }

        $body = collect($rows)->map(fn (array $row): string => '<tr>'
            .'<td>'.$this->aliquotLabel($row['id'] ?? null, $row['rate'] ?? null).'</td>'
            .'<td class="num">$ '.$this->money($row['sales_base'] ?? 0).'</td>'
            .'<td class="num">$ '.$this->money($row['sales_iva'] ?? 0).'</td>'
            .'<td class="num">$ '.$this->money($row['purchase_base'] ?? 0).'</td>'
            .'<td class="num">$ '.$this->money($row['purchase_iva'] ?? 0).'</td>'
            .'<td class="num"><strong>$ '.$this->money($row['saldo'] ?? 0).'</strong></td>'
            .'</tr>')->implode('');

        return '<section><div class="section-head"><div><h2>IVA por alicuota</h2><p>Comparativo ventas vs compras para validar libro IVA y saldo tecnico.</p></div></div>'
            .'<div class="table-wrap"><table><thead><tr><th>Alicuota</th><th class="num">Base ventas</th><th class="num">IVA ventas</th><th class="num">Base compras</th><th class="num">IVA compras</th><th class="num">Saldo IVA</th></tr></thead><tbody>'.$body.'</tbody></table></div></section>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function mixSection(string $title, array $rows, string $id): string
    {
        if ($rows === []) {
            return '<section><div class="section-head"><div><h2>'.e($title).'</h2></div></div><div class="empty">Sin movimientos.</div></section>';
        }

        $body = collect($rows)->map(fn (array $row): string => '<tr>'
            .'<td>'.e((string) ($row['document_type'] ?? '-')).'</td>'
            .'<td>'.e((string) ($row['document_kind'] ?? '-')).'</td>'
            .'<td class="num">'.e((string) ($row['count'] ?? 0)).'</td>'
            .'<td class="num">$ '.$this->money($row['imp_neto'] ?? 0).'</td>'
            .'<td class="num">$ '.$this->money($row['imp_iva'] ?? 0).'</td>'
            .'<td class="num">$ '.$this->money($row['imp_total'] ?? 0).'</td>'
            .'</tr>')->implode('');

        return '<section><div class="section-head"><div><h2>'.e($title).'</h2><p>Agrupado por tipo y clase de comprobante.</p></div></div>'
            .'<div class="table-wrap"><table id="'.e($id).'"><thead><tr><th>Tipo</th><th>Clase</th><th class="num">Cantidad</th><th class="num">Neto</th><th class="num">IVA</th><th class="num">Total</th></tr></thead><tbody>'.$body.'</tbody></table></div></section>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function paymentSection(string $title, array $rows): string
    {
        if ($rows === []) {
            return '<section><div class="section-head"><div><h2>'.e($title).'</h2></div></div><div class="empty">Sin medios de pago registrados.</div></section>';
        }

        $body = collect($rows)->map(fn (array $row): string => '<tr>'
            .'<td>'.e($this->paymentLabel($row['method'] ?? null)).'</td>'
            .'<td class="num">'.e((string) ($row['count'] ?? 0)).'</td>'
            .'<td class="num">$ '.$this->money($row['amount'] ?? 0).'</td>'
            .'</tr>')->implode('');

        return '<section><div class="section-head"><div><h2>'.e($title).'</h2><p>Efectivo, transferencia, debito, credito u otros segun payload registrado.</p></div></div>'
            .'<div class="table-wrap"><table><thead><tr><th>Medio</th><th class="num">Cantidad</th><th class="num">Importe</th></tr></thead><tbody>'.$body.'</tbody></table></div></section>';
    }

    /**
     * @param  array<string, mixed>|null  $book
     */
    private function bookSection(string $title, ?array $book, string $counterpartyLabel, bool $showAuthorization, string $tableId): string
    {
        $records = collect(data_get($book, 'records', []));
        $totals = (array) data_get($book, 'totals', []);

        $summary = '<div class="summary">'
            .$this->metric('Total', $totals['imp_total'] ?? 0)
            .$this->metric('Neto gravado', $totals['imp_neto'] ?? 0)
            .$this->metric(str_contains($title, 'Compras') ? 'IVA credito' : 'IVA', $totals['imp_iva'] ?? 0)
            .$this->metric('Tributos', $totals['imp_trib'] ?? 0)
            .'</div>';

        if ($records->isEmpty()) {
            return '<section><div class="section-head"><div><h2>'.e($title).'</h2><p>Libro fiscal listo para consumir por API.</p></div></div>'.$summary.'<div class="empty">Sin registros para el filtro seleccionado.</div></section>';
        }

        $authorizationHeader = $showAuthorization ? '<th>CAE/Aut.</th>' : '';
        $rows = $records->map(function (array $record) use ($showAuthorization): string {
            $authorizationCell = $showAuthorization ? '<td>'.e((string) data_get($record, 'authorization_code')).'</td>' : '';

            return '<tr>'
                .'<td>'.e((string) data_get($record, 'voucher_date')).'</td>'
                .'<td>'.e((string) data_get($record, 'document_type')).' ('.e((string) data_get($record, 'cbte_type')).')</td>'
                .'<td>'.e((string) data_get($record, 'point_of_sale')).'</td>'
                .'<td>'.e((string) data_get($record, 'number')).'</td>'
                .'<td>'.e((string) data_get($record, 'counterparty_name')).'</td>'
                .'<td>'.e((string) data_get($record, 'counterparty_cuit')).'</td>'
                .'<td>'.e((string) data_get($record, 'counterparty_iva_condition')).'</td>'
                .$authorizationCell
                .'<td class="num">$ '.$this->money(data_get($record, 'amounts.imp_neto', 0)).'</td>'
                .'<td class="num">$ '.$this->money(data_get($record, 'amounts.imp_iva', 0)).'</td>'
                .'<td>'.$this->ivaItems((array) data_get($record, 'iva_items', [])).'</td>'
                .'<td class="num">$ '.$this->money(data_get($record, 'amounts.imp_total', 0)).'</td>'
                .'</tr>';
        })->implode('');

        return '<section><div class="section-head"><div><h2>'.e($title).'</h2><p>Datos consultables por API, sin recalculo desde frontend.</p></div>'
            .'<div class="toolbar"><input type="search" placeholder="Buscar en '.e($title).'" data-filter-target="#'.e($tableId).'"></div></div>'
            .$summary.'<div class="table-wrap"><table id="'.e($tableId).'"><thead><tr>'
            .'<th>Fecha</th><th>Tipo</th><th>Pto Vta</th><th>Numero</th><th>'.e($counterpartyLabel).'</th><th>CUIT/Doc</th><th>Cond. IVA</th>'.$authorizationHeader
            .'<th class="num">Neto</th><th class="num">IVA</th><th>Alicuotas</th><th class="num">Total</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function ivaItems(array $items): string
    {
        if ($items === []) {
            return '<span class="tiny">Sin IVA</span>';
        }

        return '<div class="iva-tags">'.collect($items)->map(fn (mixed $item): string => '<span>'
            .e($this->aliquotLabel(data_get($item, 'id'), data_get($item, 'rate')))
            .' / $ '.$this->money(data_get($item, 'importe', 0))
            .'</span>')->implode('').'</div>';
    }

    private function metric(string $label, mixed $value, string $format = 'money', string $hint = ''): string
    {
        $formatted = $format === 'number'
            ? number_format((float) $value, 0, ',', '.')
            : '$ '.$this->money($value);

        return '<div class="metric"><span>'.e($label).'</span><strong>'.e($formatted).'</strong>'
            .($hint !== '' ? '<small>'.e($hint).'</small>' : '')
            .'</div>';
    }

    private function fact(string $label, mixed $value): string
    {
        return '<div class="fact"><span>'.e($label).'</span><strong>'.e((string) ($value ?? '-')).'</strong></div>';
    }

    private function badge(string $label, string $tone): string
    {
        return '<span class="badge '.e($tone).'">'.e($label).'</span>';
    }

    /**
     * @return array<string, string>
     */
    private function companyStatus(FiscalCompany $company): array
    {
        $credential = $company->activeCredential()
            ->first(['id', 'fiscal_company_id', 'key_name', 'certificate_expires_at', 'status', 'active']);
        $ticket = $company->accessTickets()
            ->orderByDesc('expiration_time')
            ->first(['id', 'fiscal_company_id', 'service', 'expiration_time', 'last_used_at']);

        return [
            'credential' => $credential
                ? trim(($credential->key_name ?: 'Activa').' '.($credential->certificate_expires_at ? 'hasta '.$credential->certificate_expires_at->toDateString() : ''))
                : 'No',
            'ticket' => $ticket
                ? $ticket->service.' hasta '.$ticket->expiration_time->toDateTimeString()
                : 'No disponible',
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function alerts(FiscalCompany $company, float $ivaDebit, float $ivaCredit, string $dateFrom, string $dateTo): array
    {
        $alerts = [];

        if (! $company->enabled) {
            $alerts[] = ['tone' => 'bad', 'message' => 'La empresa fiscal esta deshabilitada.'];
        }

        if ($company->fiscal_condition !== 'responsable_inscripto') {
            $alerts[] = ['tone' => 'warn', 'message' => 'La empresa no figura como Responsable Inscripto; Factura A/B no deberia emitirse para este emisor.'];
        }

        if (! $company->activeCredential()->exists()) {
            $alerts[] = ['tone' => 'warn', 'message' => 'No hay credencial fiscal activa registrada.'];
        }

        if ($this->problemDocuments($company, $dateFrom, $dateTo)->isNotEmpty()) {
            $alerts[] = ['tone' => 'bad', 'message' => 'Hay comprobantes con errores, rechazo o estado incierto en el periodo.'];
        }

        $alerts[] = [
            'tone' => $ivaDebit - $ivaCredit >= 0 ? 'info' : 'warn',
            'message' => 'Saldo IVA estimado del filtro: $ '.$this->money($ivaDebit - $ivaCredit).'.',
        ];

        return $alerts;
    }

    private function quickRangeLinks(FiscalCompany $company, string $adminToken): string
    {
        $today = now();
        $base = ['company' => $company->external_business_id];

        if ($adminToken !== '') {
            $base['admin_token'] = $adminToken;
        }

        $ranges = [
            'Hoy' => $base + ['date_from' => $today->toDateString(), 'date_to' => $today->toDateString()],
            'Mes actual' => $base + ['date_from' => $today->copy()->startOfMonth()->toDateString(), 'date_to' => $today->copy()->endOfMonth()->toDateString()],
            'Ultimos 30 dias' => $base + ['date_from' => $today->copy()->subDays(30)->toDateString(), 'date_to' => $today->toDateString()],
            'Todo' => $base,
        ];

        return '<div class="quick">'.collect($ranges)->map(fn (array $query, string $label): string => '<a class="btn ghost" href="/api/admin/?'.e(http_build_query($query)).'">'.e($label).'</a>')->implode('').'</div>';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function mixRows(Collection $records): array
    {
        return $records
            ->groupBy(fn (array $record): string => (string) data_get($record, 'document_type', 'unknown'))
            ->map(function (Collection $group): array {
                $first = (array) $group->first();

                return [
                    'document_type' => data_get($first, 'document_type', '-'),
                    'document_kind' => data_get($first, 'document_kind', '-'),
                    'count' => $group->count(),
                    'imp_neto' => $group->sum(fn (array $record): float => (float) data_get($record, 'amounts.imp_neto', 0)),
                    'imp_iva' => $group->sum(fn (array $record): float => (float) data_get($record, 'amounts.imp_iva', 0)),
                    'imp_total' => $group->sum(fn (array $record): float => (float) data_get($record, 'amounts.imp_total', 0)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $salesTotals
     * @param  array<string, mixed>  $purchaseTotals
     * @return array<int, array<string, mixed>>
     */
    private function aliquotRows(array $salesTotals, array $purchaseTotals): array
    {
        $rows = [];

        foreach ((array) ($salesTotals['iva_by_aliquot'] ?? []) as $item) {
            $id = (int) ($item['id'] ?? 0);
            $rows[$id] ??= ['id' => $id, 'rate' => $item['rate'] ?? null, 'sales_base' => 0, 'sales_iva' => 0, 'purchase_base' => 0, 'purchase_iva' => 0, 'saldo' => 0];
            $rows[$id]['sales_base'] += (float) ($item['base_imp'] ?? 0);
            $rows[$id]['sales_iva'] += (float) ($item['importe'] ?? 0);
        }

        foreach ((array) ($purchaseTotals['iva_by_aliquot'] ?? []) as $item) {
            $id = (int) ($item['id'] ?? 0);
            $rows[$id] ??= ['id' => $id, 'rate' => $item['rate'] ?? null, 'sales_base' => 0, 'sales_iva' => 0, 'purchase_base' => 0, 'purchase_iva' => 0, 'saldo' => 0];
            $rows[$id]['purchase_base'] += (float) ($item['base_imp'] ?? 0);
            $rows[$id]['purchase_iva'] += (float) ($item['importe'] ?? 0);
        }

        foreach ($rows as &$row) {
            $row['saldo'] = (float) $row['sales_iva'] - (float) $row['purchase_iva'];
        }

        return collect($rows)
            ->sortBy(fn (array $row): float => (float) ($row['rate'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paymentMix(FiscalCompany $company, string $dateFrom, string $dateTo, bool $sales): array
    {
        $records = $sales
            ? $this->documentQuery($company, $dateFrom, $dateTo)->where('status', 'authorized')->get(['payment_method', 'payment_amount', 'imp_total'])
            : $this->purchaseQuery($company, $dateFrom, $dateTo)->get(['payment_method', 'imp_total']);

        return $records
            ->groupBy(fn (FiscalDocument|FiscalPurchase $record): string => $record->payment_method ?: 'sin_informar')
            ->map(fn (Collection $group, string $method): array => [
                'method' => $method,
                'count' => $group->count(),
                'amount' => $group->sum(fn (FiscalDocument|FiscalPurchase $record): float => (float) ($record instanceof FiscalDocument ? ($record->payment_amount ?? $record->imp_total) : $record->imp_total)),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, FiscalDocument>
     */
    private function recentDocuments(FiscalCompany $company, string $dateFrom, string $dateTo): Collection
    {
        return $this->documentQuery($company, $dateFrom, $dateTo)
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * @return Collection<int, FiscalPurchase>
     */
    private function recentPurchases(FiscalCompany $company, string $dateFrom, string $dateTo): Collection
    {
        return $this->purchaseQuery($company, $dateFrom, $dateTo)
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * @return Collection<int, FiscalDocument>
     */
    private function problemDocuments(FiscalCompany $company, string $dateFrom, string $dateTo): Collection
    {
        return $this->documentQuery($company, $dateFrom, $dateTo)
            ->whereIn('status', ['error', 'rejected', 'uncertain'])
            ->latest('created_at')
            ->limit(20)
            ->get();
    }

    private function documentQuery(FiscalCompany $company, string $dateFrom, string $dateTo): mixed
    {
        $query = $company->documents();

        if ($dateFrom !== '') {
            $query->whereDate('voucher_date', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate('voucher_date', '<=', $dateTo);
        }

        return $query;
    }

    private function purchaseQuery(FiscalCompany $company, string $dateFrom, string $dateTo): mixed
    {
        $query = $company->purchases();

        if ($dateFrom !== '') {
            $query->whereDate('voucher_date', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate('voucher_date', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * @param  array<int, FiscalDocument>  $documents
     */
    private function recentDocumentsSection(array $documents): string
    {
        if ($documents === []) {
            return '<section><div class="section-head"><div><h2>Ultimos documentos</h2></div></div><div class="empty">Sin documentos fiscales.</div></section>';
        }

        $rows = collect($documents)->map(fn (FiscalDocument $document): string => '<tr>'
            .'<td>'.e($document->created_at?->format('Y-m-d H:i') ?? '-').'</td>'
            .'<td>'.e($document->document_type ?: '-').'<div class="tiny">Cbte '.$document->voucher_type.' / PV '.$document->point_of_sale.'</div></td>'
            .'<td>'.e((string) ($document->document_number ?? '-')).'</td>'
            .'<td>'.$this->statusBadge((string) $document->status).'</td>'
            .'<td class="num">$ '.$this->money($document->imp_total ?? 0).'</td>'
            .'<td>'.e($document->authorization_code ?: '-').'</td>'
            .'</tr>')->implode('');

        return '<section><div class="section-head"><div><h2>Ultimos documentos</h2><p>Incluye borradores, errores, rechazados y autorizados.</p></div></div>'
            .'<div class="table-wrap"><table><thead><tr><th>Creado</th><th>Tipo</th><th>Numero</th><th>Estado</th><th class="num">Total</th><th>Autorizacion</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
    }

    /**
     * @param  array<int, FiscalPurchase>  $purchases
     */
    private function recentPurchasesSection(array $purchases): string
    {
        if ($purchases === []) {
            return '<section><div class="section-head"><div><h2>Ultimas compras</h2></div></div><div class="empty">Sin compras cargadas.</div></section>';
        }

        $rows = collect($purchases)->map(fn (FiscalPurchase $purchase): string => '<tr>'
            .'<td>'.e($purchase->voucher_date?->toDateString() ?? '-').'</td>'
            .'<td>'.e($purchase->document_type).'<div class="tiny">Cbte '.$purchase->voucher_type.' / PV '.$purchase->point_of_sale.'</div></td>'
            .'<td>'.e((string) $purchase->document_number).'</td>'
            .'<td>'.e($purchase->supplier_name ?: '-').'<div class="tiny">'.e($purchase->supplier_cuit).'</div></td>'
            .'<td class="num">$ '.$this->money($purchase->imp_iva).'</td>'
            .'<td class="num">$ '.$this->money($purchase->imp_total).'</td>'
            .'</tr>')->implode('');

        return '<section><div class="section-head"><div><h2>Ultimas compras</h2><p>Comprobantes de proveedores cargados manualmente.</p></div></div>'
            .'<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Numero</th><th>Proveedor</th><th class="num">IVA</th><th class="num">Total</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
    }

    /**
     * @param  array<int, FiscalDocument>  $documents
     */
    private function problemSection(array $documents, string $adminToken): string
    {
        if ($documents === []) {
            return '<section><div class="section-head"><div><h2>Errores y rechazos</h2><p>No hay comprobantes problematicos en el filtro actual.</p></div></div></section>';
        }

        $rows = collect($documents)->map(fn (FiscalDocument $document): string => '<tr>'
            .'<td>'.e($document->created_at?->format('Y-m-d H:i') ?? '-').'</td>'
            .'<td>'.e($document->document_type ?: '-').'</td>'
            .'<td>'.$this->statusBadge((string) $document->status).'</td>'
            .'<td>'.e($document->error_code ?: '-').'</td>'
            .'<td>'.e($document->error_message ?: '-').'</td>'
            .'<td><code>/api/fiscal/documents/'.$document->id.'</code></td>'
            .'</tr>')->implode('');

        return '<section><div class="section-head"><div><h2>Errores y rechazos</h2><p>Revision rapida para retry, reconcile o correccion de payload.</p></div></div>'
            .'<div class="table-wrap"><table><thead><tr><th>Creado</th><th>Tipo</th><th>Estado</th><th>Codigo</th><th>Mensaje</th><th>Endpoint</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
    }

    private function statusBadge(string $status): string
    {
        $tone = match ($status) {
            'authorized' => 'good',
            'rejected', 'error' => 'bad',
            'uncertain' => 'warn',
            default => 'neutral',
        };

        return $this->badge($status, $tone);
    }

    private function paymentLabel(mixed $method): string
    {
        return match ((string) $method) {
            'cash' => 'Efectivo',
            'bank_transfer' => 'Transferencia',
            'debit_card' => 'Tarjeta debito',
            'credit_card' => 'Tarjeta credito',
            'other' => 'Otro',
            'sin_informar', '' => 'Sin informar',
            default => (string) $method,
        };
    }

    private function aliquotLabel(mixed $id, mixed $rate): string
    {
        $rate = $rate !== null && $rate !== '' ? number_format((float) $rate, 2, ',', '.') : null;

        return 'ID '.e((string) ($id ?? '-')).($rate !== null ? ' / '.$rate.'%' : '');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
