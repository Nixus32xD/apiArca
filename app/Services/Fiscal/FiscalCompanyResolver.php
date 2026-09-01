<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;

class FiscalCompanyResolver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function fromPayload(array $payload): FiscalCompany
    {
        $identifier = $payload['external_fiscal_id']
            ?? $payload['external_business_id']
            ?? $payload['business_id']
            ?? null;

        if (! is_scalar($identifier) || (string) $identifier === '') {
            throw new FiscalException('The fiscal company identifier is required.', 422, 'company_identifier_required');
        }

        return $this->resolveExternalIdentifier((string) $identifier);
    }

    /**
     * Public integration identifiers always resolve against the external
     * reference. A numeric value is still an external identifier, never an
     * implicit primary key.
     */
    public function resolve(string $identifier): FiscalCompany
    {
        return $this->resolveExternalIdentifier($identifier);
    }

    public function resolveExternalIdentifier(string $identifier): FiscalCompany
    {
        $company = FiscalCompany::query()
            ->where('external_business_id', $identifier)
            ->first();

        if (! $company) {
            throw new FiscalException('Fiscal company was not found.', 404, 'company_not_found', [
                'identifier' => $identifier,
            ]);
        }

        $this->assertCurrentClientCanAccess($company);

        return $company;
    }

    public function resolveInternalId(int $id): FiscalCompany
    {
        $company = FiscalCompany::query()->find($id);

        if (! $company) {
            throw new FiscalException('Fiscal company was not found.', 404, 'company_not_found', ['id' => $id]);
        }

        return $company;
    }

    private function assertCurrentClientCanAccess(FiscalCompany $company): void
    {
        $client = request()?->attributes->get('fiscal_client');
        if (! is_array($client) || ! array_key_exists('external_fiscal_ids', $client) || $client['external_fiscal_ids'] === null) {
            return;
        }

        $allowed = array_map('strval', $client['external_fiscal_ids']);
        if (in_array('*', $allowed, true) || in_array((string) $company->external_business_id, $allowed, true)) {
            return;
        }

        throw new FiscalException('El cliente de API no está autorizado para esta identidad fiscal.', 403, 'fiscal_company_forbidden');
    }
}
