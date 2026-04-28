<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use App\Services\Fiscal\Support\ArcaErrorMapper;
use Illuminate\Support\Carbon;

class FiscalDiagnosticsService
{
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly TokenCacheService $tokenCache,
        private readonly Wsfev1Client $wsfev1,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(FiscalCompany $company, ?string $traceId = null): array
    {
        $checks = [
            'company' => $this->companyDiagnostic($company),
        ];

        $credential = null;
        $ticket = null;

        try {
            $credential = $this->credentialStore->activeFor($company);
            $checks['credential'] = $this->okCheck('Credencial fiscal activa.');
            $checks['certificate'] = $this->certificateDiagnostic($credential, $company);
        } catch (FiscalException $exception) {
            $checks['credential'] = $this->exceptionCheck($exception);
            $checks['certificate'] = $this->skippedCheck('No se valida certificado porque la credencial no está activa.');
        }

        if ($credential) {
            try {
                $ticket = $this->tokenCache->get($company);
                $checks['wsaa'] = $this->okCheck('WSAA devolvió o reutilizó un ticket válido.', [
                    'generation_time' => $ticket->generation_time?->toIso8601String(),
                    'expiration_time' => $ticket->expiration_time->toIso8601String(),
                ]);
            } catch (FiscalException $exception) {
                $checks['wsaa'] = $this->exceptionCheck($exception);
            }
        } else {
            $checks['wsaa'] = $this->skippedCheck('No se valida WSAA porque falta credencial activa.');
        }

        try {
            $checks['fedummy'] = $this->okCheck('FEDummy respondió correctamente.', [
                'response' => $this->wsfev1->dummy($company, $traceId),
            ]);
        } catch (FiscalException $exception) {
            $checks['fedummy'] = $this->exceptionCheck($exception);
        }

        if ($ticket) {
            try {
                $checks['wsfev1'] = $this->okCheck('WSFEv1 respondió con autenticación para la CUIT.', [
                    'points_of_sale' => $this->wsfev1->pointsOfSale($company, $ticket, $traceId),
                ]);
            } catch (FiscalException $exception) {
                $checks['wsfev1'] = $this->exceptionCheck($exception);
            }
        } else {
            $checks['wsfev1'] = $this->skippedCheck('No se valida WSFEv1 autenticado porque no hay ticket WSAA válido.');
        }

        return [
            'ok' => collect($checks)->every(fn (array $check): bool => (bool) ($check['ok'] ?? false)),
            'company_id' => $company->id,
            'business_id' => $company->external_business_id,
            'environment' => $company->environment,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companyDiagnostic(FiscalCompany $company): array
    {
        if (! $company->enabled) {
            return [
                'ok' => false,
                'message' => 'La empresa fiscal está deshabilitada.',
                'error_code' => 'company_disabled',
            ];
        }

        if (! preg_match('/^\d{11}$/', $company->cuit)) {
            return [
                'ok' => false,
                'message' => 'La CUIT configurada no tiene 11 dígitos.',
                'error_code' => 'company_cuit_invalid',
            ];
        }

        return $this->okCheck('Empresa fiscal habilitada y CUIT con formato válido.', [
            'cuit' => $company->cuit,
            'legal_name' => $company->legal_name,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function certificateDiagnostic(FiscalCredential $credential, FiscalCompany $company): array
    {
        $parsed = openssl_x509_parse($credential->certificate, false);

        if (! is_array($parsed)) {
            return [
                'ok' => false,
                'message' => 'No se pudo leer el certificado fiscal.',
                'error_code' => 'certificate_invalid',
            ];
        }

        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;

        if ($validTo !== null && $validTo < time()) {
            return [
                'ok' => false,
                'message' => 'El certificado fiscal está vencido.',
                'error_code' => 'certificate_expired',
            ];
        }

        $certificatePublicKey = openssl_pkey_get_public($credential->certificate);
        $privateKey = openssl_pkey_get_private($credential->private_key, $credential->passphrase ?: null);

        if ($certificatePublicKey === false || $privateKey === false) {
            return [
                'ok' => false,
                'message' => ArcaErrorMapper::AUTH_MESSAGE,
                'error_code' => 'private_key_invalid',
            ];
        }

        $certificateDetails = openssl_pkey_get_details($certificatePublicKey);
        $privateKeyDetails = openssl_pkey_get_details($privateKey);
        $keysMatch = is_array($certificateDetails)
            && is_array($privateKeyDetails)
            && is_string($certificateDetails['key'] ?? null)
            && is_string($privateKeyDetails['key'] ?? null)
            && hash_equals(hash('sha256', $privateKeyDetails['key']), hash('sha256', $certificateDetails['key']));

        if (! $keysMatch) {
            return [
                'ok' => false,
                'message' => 'El certificado fiscal no corresponde a la clave privada guardada.',
                'error_code' => 'certificate_private_key_mismatch',
            ];
        }

        $serialNumber = (string) data_get($parsed, 'subject.serialNumber', '');

        return $this->okCheck('Certificado fiscal vigente y consistente con la clave privada.', [
            'valid_to' => $validTo ? Carbon::createFromTimestamp($validTo)->toIso8601String() : null,
            'subject_serial_number' => $serialNumber ?: null,
            'matches_company_cuit' => $serialNumber === '' || str_contains($serialNumber, $company->cuit),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function okCheck(string $message, array $data = []): array
    {
        return array_filter([
            'ok' => true,
            'message' => $message,
            'data' => $data,
        ], fn ($value) => $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function skippedCheck(string $message): array
    {
        return [
            'ok' => false,
            'skipped' => true,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionCheck(FiscalException $exception): array
    {
        return array_filter([
            'ok' => false,
            'message' => ArcaErrorMapper::messageForException($exception),
            'error_code' => $exception->errorCode(),
            'context' => $exception->context(),
        ], fn ($value) => $value !== null && $value !== []);
    }
}
