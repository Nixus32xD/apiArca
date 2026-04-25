<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;

class CredentialStore
{
    public function activeFor(FiscalCompany $company): FiscalCredential
    {
        if (! $company->enabled) {
            throw new FiscalException('Fiscal company is disabled.', 409, 'company_disabled');
        }

        if (! in_array($company->environment, ['testing', 'production'], true)) {
            throw new FiscalException('Fiscal company environment is invalid.', 422, 'invalid_company_environment');
        }

        /** @var FiscalCredential|null $credential */
        $credential = $company->activeCredential()->first();

        if (! $credential) {
            throw new FiscalException('Fiscal company has no active credentials.', 409, 'credentials_missing');
        }

        if ($credential->status === 'pending_certificate') {
            throw new FiscalException('Fiscal credential is waiting for the ARCA certificate.', 409, 'credentials_pending_certificate');
        }

        if (! is_string($credential->certificate) || trim($credential->certificate) === '') {
            throw new FiscalException('Fiscal certificate is missing.', 409, 'certificate_missing');
        }

        if (! is_string($credential->private_key) || trim($credential->private_key) === '') {
            throw new FiscalException('Fiscal private key is missing.', 409, 'private_key_missing');
        }

        if ($credential->certificate_expires_at && $credential->certificate_expires_at->isPast()) {
            throw new FiscalException('Fiscal certificate is expired.', 409, 'certificate_expired');
        }

        return $credential;
    }
}
