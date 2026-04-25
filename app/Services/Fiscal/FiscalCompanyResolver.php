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
        $businessId = $payload['external_business_id'] ?? $payload['business_id'] ?? null;

        if (! is_scalar($businessId) || (string) $businessId === '') {
            throw new FiscalException('The fiscal company identifier is required.', 422, 'company_identifier_required');
        }

        return $this->resolve((string) $businessId);
    }

    public function resolve(string $identifier): FiscalCompany
    {
        $company = FiscalCompany::query()
            ->where('external_business_id', $identifier)
            ->first();

        if (! $company && is_numeric($identifier)) {
            $company = FiscalCompany::query()->find((int) $identifier);
        }

        if (! $company) {
            throw new FiscalException('Fiscal company was not found.', 404, 'company_not_found', [
                'identifier' => $identifier,
            ]);
        }

        return $company;
    }
}
