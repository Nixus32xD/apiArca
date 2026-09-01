<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use Illuminate\Http\Request;

class FiscalRecordScopeGuard
{
    public function __construct(
        private readonly FiscalCompanyResolver $companyResolver,
    ) {}

    public function ensureCompanyMatches(Request $request, FiscalCompany $company): void
    {
        $identifier = $this->scopeIdentifier($request);

        if ($identifier === null) {
            if ((bool) config('fiscal.security.require_company_scope_for_id_routes', false)) {
                throw new FiscalException('Fiscal company scope is required for this endpoint.', 422, 'company_scope_required');
            }

            $this->ensureClientCanAccessCompany($request, $company);

            return;
        }

        $scopedCompany = $this->companyResolver->resolveExternalIdentifier($identifier);

        if ((int) $scopedCompany->id !== (int) $company->id) {
            throw new FiscalException('The fiscal record does not belong to the requested company.', 403, 'company_scope_mismatch');
        }

        $this->ensureClientCanAccessCompany($request, $company);
    }

    public function ensureClientCanAccessCompany(Request $request, FiscalCompany $company): void
    {
        $client = $request->attributes->get('fiscal_client');
        if (! is_array($client) || ! array_key_exists('external_fiscal_ids', $client) || $client['external_fiscal_ids'] === null) {
            return;
        }

        $allowed = array_map('strval', $client['external_fiscal_ids']);
        if (in_array('*', $allowed, true) || in_array((string) $company->external_business_id, $allowed, true)) {
            return;
        }

        throw new FiscalException('El cliente de API no está autorizado para esta identidad fiscal.', 403, 'fiscal_company_forbidden');
    }

    private function scopeIdentifier(Request $request): ?string
    {
        $value = $request->input('external_fiscal_id')
            ?? $request->input('business_id')
            ?? $request->input('external_business_id')
            ?? $request->query('external_fiscal_id')
            ?? $request->query('business_id')
            ?? $request->query('external_business_id')
            ?? $request->header('X-Fiscal-External-Id')
            ?? $request->header('X-Fiscal-Business-Id')
            ?? $request->header('X-Fiscal-Company-Id');

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
