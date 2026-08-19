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

            return;
        }

        $scopedCompany = $this->companyResolver->resolve($identifier);

        if ((int) $scopedCompany->id !== (int) $company->id) {
            throw new FiscalException('The fiscal record does not belong to the requested company.', 403, 'company_scope_mismatch');
        }
    }

    private function scopeIdentifier(Request $request): ?string
    {
        $value = $request->input('business_id')
            ?? $request->input('external_business_id')
            ?? $request->query('business_id')
            ?? $request->query('external_business_id')
            ?? $request->header('X-Fiscal-Business-Id')
            ?? $request->header('X-Fiscal-Company-Id');

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
