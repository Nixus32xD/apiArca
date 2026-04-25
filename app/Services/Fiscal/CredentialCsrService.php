<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;
use Throwable;

class CredentialCsrService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{credential: FiscalCredential, created: bool}
     */
    public function getOrCreate(FiscalCompany $company, array $data): array
    {
        $keyName = (string) $data['key_name'];

        /** @var FiscalCredential|null $existing */
        $existing = $company->credentials()
            ->where('key_name', $keyName)
            ->latest()
            ->first();

        if ($existing) {
            if (! is_string($existing->csr) || trim($existing->csr) === '') {
                throw new FiscalException('Fiscal credential key name already exists without a CSR.', 409, 'credential_key_name_conflict');
            }

            return [
                'credential' => $existing,
                'created' => false,
            ];
        }

        $generated = $this->generate($company, $data);

        /** @var FiscalCredential $credential */
        $credential = $company->credentials()->create([
            'key_name' => $keyName,
            'certificate' => '',
            'private_key' => $generated['private_key'],
            'passphrase' => $data['passphrase'] ?? null,
            'csr' => $generated['csr'],
            'certificate_expires_at' => null,
            'active' => false,
            'status' => 'pending_certificate',
            'metadata' => array_merge($data['metadata'] ?? [], [
                'csr_subject' => $generated['subject'],
            ]),
        ]);

        return [
            'credential' => $credential,
            'created' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeCertificate(FiscalCompany $company, FiscalCredential $credential, array $data): FiscalCredential
    {
        if ($credential->fiscal_company_id !== $company->id) {
            throw new FiscalException('Fiscal credential was not found for this company.', 404, 'credential_not_found');
        }

        $certificate = trim((string) $data['certificate']);
        $expiresAt = $this->certificateExpiration($certificate);

        if ($expiresAt->isPast()) {
            throw new FiscalException('Fiscal certificate is expired.', 409, 'certificate_expired');
        }

        $this->assertCertificateMatchesPrivateKey($credential, $certificate);

        $active = (bool) ($data['active'] ?? true);
        $metadata = array_merge($credential->metadata ?? [], $data['metadata'] ?? [], [
            'certificate_uploaded_at' => now()->toIso8601String(),
        ]);

        return DB::transaction(function () use ($company, $credential, $certificate, $expiresAt, $active, $metadata): FiscalCredential {
            if ($active) {
                $company->credentials()
                    ->where('id', '!=', $credential->id)
                    ->update([
                        'active' => false,
                        'status' => 'inactive',
                    ]);
            }

            $credential->forceFill([
                'certificate' => $certificate,
                'certificate_expires_at' => $expiresAt,
                'active' => $active,
                'status' => $active ? 'active' : 'inactive',
                'metadata' => $metadata,
            ])->save();

            return $credential->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{private_key: string, csr: string, subject: array<string, string>}
     */
    private function generate(FiscalCompany $company, array $data): array
    {
        $config = $this->opensslConfig();
        $subject = [
            'countryName' => strtoupper((string) ($data['country_name'] ?? 'AR')),
            'organizationName' => (string) ($data['organization_name'] ?? $company->legal_name),
            'commonName' => (string) ($data['common_name'] ?? $company->external_business_id),
            'serialNumber' => 'CUIT '.$company->cuit,
        ];

        $this->clearOpenSslErrors();

        $privateKey = openssl_pkey_new($config);

        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            throw new FiscalException('OpenSSL could not generate the fiscal private key.', 500, 'private_key_generation_failed', [
                'openssl_error' => $this->lastOpenSslError(),
            ]);
        }

        $exported = openssl_pkey_export(
            $privateKey,
            $privateKeyPem,
            $data['passphrase'] ?? null,
            $config,
        );

        if (! $exported || ! is_string($privateKeyPem) || trim($privateKeyPem) === '') {
            throw new FiscalException('OpenSSL could not export the fiscal private key.', 500, 'private_key_export_failed', [
                'openssl_error' => $this->lastOpenSslError(),
            ]);
        }

        $csr = openssl_csr_new($subject, $privateKey, $config);

        if ($csr === false) {
            throw new FiscalException('OpenSSL could not generate the fiscal CSR.', 500, 'csr_generation_failed', [
                'openssl_error' => $this->lastOpenSslError(),
            ]);
        }

        $exportedCsr = openssl_csr_export($csr, $csrPem, true);

        if (! $exportedCsr || ! is_string($csrPem) || trim($csrPem) === '') {
            throw new FiscalException('OpenSSL could not export the fiscal CSR.', 500, 'csr_export_failed', [
                'openssl_error' => $this->lastOpenSslError(),
            ]);
        }

        return [
            'private_key' => $privateKeyPem,
            'csr' => $csrPem,
            'subject' => $subject,
        ];
    }

    private function assertCertificateMatchesPrivateKey(FiscalCredential $credential, string $certificate): void
    {
        $certificatePublicKey = openssl_pkey_get_public($certificate);

        if (! $certificatePublicKey instanceof OpenSSLAsymmetricKey) {
            throw new FiscalException('Fiscal certificate could not be opened.', 409, 'certificate_invalid');
        }

        $privateKey = openssl_pkey_get_private($credential->private_key, $credential->passphrase ?: null);

        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            throw new FiscalException('Fiscal private key could not be opened.', 409, 'private_key_invalid');
        }

        $certificateDetails = openssl_pkey_get_details($certificatePublicKey);
        $privateKeyDetails = openssl_pkey_get_details($privateKey);

        if (
            ! is_array($certificateDetails)
            || ! is_array($privateKeyDetails)
            || ! is_string($certificateDetails['key'] ?? null)
            || ! is_string($privateKeyDetails['key'] ?? null)
            || ! hash_equals(hash('sha256', $privateKeyDetails['key']), hash('sha256', $certificateDetails['key']))
        ) {
            throw new FiscalException('Fiscal certificate does not match the stored private key.', 409, 'certificate_private_key_mismatch');
        }
    }

    private function certificateExpiration(string $certificate): Carbon
    {
        try {
            $parsed = openssl_x509_parse($certificate, false);
        } catch (Throwable) {
            $parsed = false;
        }

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            throw new FiscalException('Fiscal certificate could not be parsed.', 409, 'certificate_invalid');
        }

        return Carbon::createFromTimestamp((int) $parsed['validTo_time_t']);
    }

    /**
     * @return array<string, mixed>
     */
    private function opensslConfig(): array
    {
        $config = [
            'private_key_bits' => (int) config('fiscal.openssl.private_key_bits', 2048),
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];

        $configPath = $this->opensslConfigPath();

        if ($configPath !== null) {
            $config['config'] = $configPath;
        }

        return $config;
    }

    private function opensslConfigPath(): ?string
    {
        $configured = config('fiscal.openssl.config_path');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $envConfig = getenv('OPENSSL_CONF');

        if (is_string($envConfig) && $envConfig !== '' && is_file($envConfig)) {
            return $envConfig;
        }

        $phpBinary = PHP_BINARY;

        if (is_string($phpBinary) && $phpBinary !== '') {
            $candidate = dirname($phpBinary).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            //
        }
    }

    private function lastOpenSslError(): ?string
    {
        return openssl_error_string() ?: null;
    }
}
