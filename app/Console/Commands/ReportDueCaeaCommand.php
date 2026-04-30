<?php

namespace App\Console\Commands;

use App\Models\FiscalCaea;
use App\Services\Fiscal\FiscalCaeaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ReportDueCaeaCommand extends Command
{
    protected $signature = 'arca:caea:report-due
        {--date= : Fecha de corte YYYY-MM-DD. Por defecto hoy.}
        {--company= : ID interno o external_business_id de empresa fiscal.}
        {--dry-run : Muestra lo que reportaria sin llamar a ARCA.}';

    protected $description = 'Reports due CAEA vouchers to ARCA and informs without movement when no vouchers used the CAEA.';

    public function handle(FiscalCaeaService $caeaService): int
    {
        $asOf = $this->asOfDate();
        $query = FiscalCaea::query()
            ->with('company')
            ->whereIn('report_status', [FiscalCaea::STATUS_PENDING, FiscalCaea::STATUS_PARTIAL])
            ->where(function ($query) use ($asOf): void {
                $query
                    ->whereDate('valid_to', '<', $asOf)
                    ->orWhereDate('report_deadline', '<=', $asOf);
            });

        $company = trim((string) $this->option('company'));
        if ($company !== '') {
            $query->whereHas('company', function ($query) use ($company): void {
                $query->where('external_business_id', $company);

                if (is_numeric($company)) {
                    $query->orWhere('id', (int) $company);
                }
            });
        }

        $caeas = $query->orderBy('valid_to')->get();

        if ($caeas->isEmpty()) {
            $this->info('No due CAEA grants found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totals = ['grants' => 0, 'reported' => 0, 'failed' => 0, 'without_movement' => 0];

        foreach ($caeas as $caea) {
            $totals['grants']++;

            $this->line(sprintf(
                'CAEA %s company=%s period=%s order=%s valid_to=%s',
                $caea->code,
                $caea->company?->external_business_id ?? $caea->fiscal_company_id,
                $caea->period,
                $caea->order,
                $caea->valid_to?->toDateString() ?? '-',
            ));

            if ($dryRun) {
                continue;
            }

            $result = $caeaService->reportGrant($caea, 'caea-auto-'.Str::uuid()->toString());
            $totals['reported'] += $result['reported'];
            $totals['failed'] += $result['failed'];
            $totals['without_movement'] += $result['without_movement'] ? 1 : 0;
        }

        $this->info(sprintf(
            'Processed grants=%d reported_documents=%d failed_documents=%d without_movement=%d',
            $totals['grants'],
            $totals['reported'],
            $totals['failed'],
            $totals['without_movement'],
        ));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function asOfDate(): Carbon
    {
        $date = $this->option('date');

        return is_string($date) && $date !== ''
            ? Carbon::parse($date)->startOfDay()
            : now()->startOfDay();
    }
}
