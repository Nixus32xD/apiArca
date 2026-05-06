<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\GenerateFiscalCredentialCsrRequest;
use App\Http\Requests\Fiscal\StoreFiscalCredentialCertificateRequest;
use App\Http\Requests\Fiscal\StoreFiscalCredentialRequest;
use App\Http\Requests\Fiscal\UpsertFiscalCompanyRequest;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use App\Services\Fiscal\CredentialCsrService;
use App\Services\Fiscal\CredentialStore;
use App\Services\Fiscal\FiscalCompanyResolver;
use App\Services\Fiscal\FiscalDiagnosticsService;
use App\Services\Fiscal\Support\ArcaErrorMapper;
use App\Services\Fiscal\TokenCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class FiscalCompanyController extends Controller
{
    public function __construct(
        private readonly FiscalCompanyResolver $companyResolver,
        private readonly CredentialStore $credentialStore,
        private readonly CredentialCsrService $credentialCsr,
        private readonly TokenCacheService $tokenCache,
        private readonly Wsfev1Client $wsfev1,
        private readonly FiscalDiagnosticsService $diagnosticsService,
    ) {}

    public function upsert(UpsertFiscalCompanyRequest $request, ?string $company = null): JsonResponse
    {
        try {
            $data = $request->validated();

            if ($company !== null) {
                $existing = $this->companyResolver->resolve($company);
                $previousEnvironment = $existing->environment;
                $existing->update($data);
                $this->invalidateAccessTicketsIfEnvironmentChanged($existing, $previousEnvironment);

                return response()->json(['data' => $this->companyPayload($existing->refresh())]);
            }

            $fiscalCompany = FiscalCompany::query()
                ->where('external_business_id', $data['external_business_id'])
                ->first();

            if ($fiscalCompany) {
                $previousEnvironment = $fiscalCompany->environment;
                $fiscalCompany->update($data);
                $this->invalidateAccessTicketsIfEnvironmentChanged($fiscalCompany, $previousEnvironment);
            } else {
                $fiscalCompany = FiscalCompany::query()->create($data);
            }

            return response()
                ->json(['data' => $this->companyPayload($fiscalCompany->refresh())])
                ->setStatusCode($fiscalCompany->wasRecentlyCreated ? 201 : 200);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not persist fiscal company.',
                'error_code' => 'company_persist_failed',
            ], 422);
        }
    }

    public function storeCredentials(StoreFiscalCredentialRequest $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $data = $request->validated();
            $active = (bool) ($data['active'] ?? true);

            $credential = DB::transaction(function () use ($fiscalCompany, $data, $active) {
                if ($active) {
                    $fiscalCompany->credentials()->update([
                        'active' => false,
                        'status' => 'inactive',
                    ]);
                }

                return $fiscalCompany->credentials()->create([
                    'certificate' => $data['certificate'],
                    'private_key' => $data['private_key'],
                    'passphrase' => $data['passphrase'] ?? null,
                    'certificate_expires_at' => $data['certificate_expires_at'] ?? null,
                    'active' => $active,
                    'status' => $active ? 'active' : 'inactive',
                    'metadata' => $data['metadata'] ?? null,
                ]);
            });

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'credential' => [
                        'id' => $credential->id,
                        'active' => $credential->active,
                        'certificate_expires_at' => $credential->certificate_expires_at?->toIso8601String(),
                        'metadata' => $credential->metadata,
                    ],
                ],
            ], 201);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not persist fiscal credentials.',
                'error_code' => 'credentials_persist_failed',
            ], 422);
        }
    }

    public function generateCredentialsCsr(GenerateFiscalCredentialCsrRequest $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $result = $this->credentialCsr->getOrCreate($fiscalCompany, $request->validated());
            $credential = $result['credential'];

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'credential' => [
                        'id' => $credential->id,
                        'key_name' => $credential->key_name,
                        'status' => $credential->status,
                        'active' => $credential->active,
                        'certificate_expires_at' => $credential->certificate_expires_at?->toIso8601String(),
                        'metadata' => $credential->metadata,
                    ],
                    'csr' => $credential->csr,
                ],
                'meta' => [
                    'created' => $result['created'],
                ],
            ], $result['created'] ? 201 : 200);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not generate fiscal credential CSR.',
                'error_code' => 'credential_csr_generation_failed',
            ], 422);
        }
    }

    public function storeCredentialCertificate(
        StoreFiscalCredentialCertificateRequest $request,
        string $company,
        FiscalCredential $credential,
    ): JsonResponse {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $credential = $this->credentialCsr->storeCertificate($fiscalCompany, $credential, $request->validated());

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'credential' => [
                        'id' => $credential->id,
                        'key_name' => $credential->key_name,
                        'status' => $credential->status,
                        'active' => $credential->active,
                        'certificate_expires_at' => $credential->certificate_expires_at?->toIso8601String(),
                        'metadata' => $credential->metadata,
                    ],
                ],
            ]);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Could not persist fiscal credential certificate.',
                'error_code' => 'credential_certificate_persist_failed',
            ], 422);
        }
    }

    public function status(string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $credential = $fiscalCompany->activeCredential()->first()
                ?? $fiscalCompany->credentials()->latest()->first();
            $ticket = $fiscalCompany->accessTickets()
                ->where('service', (string) config('fiscal.wsaa.service', 'wsfe'))
                ->first();
            $credentialConfigured = (bool) $credential;
            $credentialActive = (bool) $credential?->active;
            $ticketConfigured = (bool) $ticket;
            $ticketValid = (bool) ($ticket && $ticket->expiration_time->isFuture());
            $ready = (bool) $fiscalCompany->enabled && $credentialActive;

            return response()->json([
                'data' => [
                    'id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'cuit' => $fiscalCompany->cuit,
                    'legal_name' => $fiscalCompany->legal_name,
                    'fiscal_condition' => $fiscalCompany->fiscal_condition,
                    'environment' => $fiscalCompany->environment,
                    'enabled' => $fiscalCompany->enabled,
                    'ready' => $ready,
                    'status_label' => $ready ? 'Listo' : 'Revisar setup',
                    'message' => $this->statusMessage(
                        (bool) $fiscalCompany->enabled,
                        $credentialConfigured,
                        $credentialActive,
                        $ticketConfigured,
                        $ticketValid,
                    ),
                    'defaults' => [
                        'point_of_sale' => $fiscalCompany->default_point_of_sale,
                        'cbte_type' => $fiscalCompany->default_voucher_type,
                    ],
                    'credential' => [
                        'configured' => $credentialConfigured,
                        'id' => $credential?->id,
                        'key_name' => $credential?->key_name,
                        'status' => $credential?->status,
                        'active' => $credentialActive,
                        'csr_generated' => $this->credentialHasValue($credential?->csr),
                        'certificate_loaded' => $this->credentialHasValue($credential?->certificate),
                        'certificate_expires_at' => $credential?->certificate_expires_at?->toIso8601String(),
                    ],
                    'access_ticket' => [
                        'configured' => $ticketConfigured,
                        'valid' => $ticketValid,
                        'generation_time' => $ticket?->generation_time?->toIso8601String(),
                        'expiration_time' => $ticket?->expiration_time?->toIso8601String(),
                        'last_used_at' => $ticket?->last_used_at?->toIso8601String(),
                        'reused_count' => $ticket?->reused_count,
                        'metadata' => $ticket?->metadata,
                    ],
                    'onboarding_metadata' => $fiscalCompany->onboarding_metadata,
                ],
            ]);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        }
    }

    public function testCredentials(Request $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $this->credentialStore->activeFor($fiscalCompany);
            $ticket = $this->tokenCache->get($fiscalCompany);
            $dummy = $this->wsfev1->dummy($fiscalCompany, $request->header('X-Trace-Id') ?: $request->header('X-Request-Id'));

            return response()->json([
                'data' => [
                    'ok' => true,
                    'environment' => $fiscalCompany->environment,
                    'token' => [
                        'generation_time' => $ticket->generation_time?->toIso8601String(),
                        'expiration_time' => $ticket->expiration_time->toIso8601String(),
                        'metadata' => $ticket->metadata,
                    ],
                    'wsfev1_dummy' => $dummy,
                ],
            ]);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unexpected fiscal credential test error.',
                'error_code' => 'unexpected_error',
            ], 500);
        }
    }

    public function diagnostics(Request $request, string $company): JsonResponse
    {
        try {
            $traceId = $request->header('X-Trace-Id') ?: $request->header('X-Request-Id');
            $fiscalCompany = $this->companyResolver->resolve($company);

            return response()->json([
                'data' => $this->diagnosticsService->run($fiscalCompany, $traceId),
            ]);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unexpected fiscal diagnostics error.',
                'error_code' => 'unexpected_error',
            ], 500);
        }
    }

    public function activities(Request $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $this->credentialStore->activeFor($fiscalCompany);
            $ticket = $this->tokenCache->get($fiscalCompany);
            $response = $this->wsfev1->activities($fiscalCompany, $ticket, $request->header('X-Trace-Id') ?: $request->header('X-Request-Id'));
            $apiError = $this->catalogError($response);

            if ($apiError !== null) {
                return response()->json([
                    'status' => 'error',
                    'error' => $apiError,
                    'data' => [
                        'company_id' => $fiscalCompany->id,
                        'business_id' => $fiscalCompany->external_business_id,
                        'environment' => $fiscalCompany->environment,
                        'activities' => [],
                    ],
                ]);
            }

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'environment' => $fiscalCompany->environment,
                    'activities' => $this->normalizeActivities($response),
                ],
            ]);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unexpected fiscal activities query error.',
                'error_code' => 'unexpected_error',
            ], 500);
        }
    }

    public function pointsOfSale(Request $request, string $company): JsonResponse
    {
        try {
            $fiscalCompany = $this->companyResolver->resolve($company);
            $this->credentialStore->activeFor($fiscalCompany);
            $ticket = $this->tokenCache->get($fiscalCompany);
            $response = $this->wsfev1->pointsOfSale($fiscalCompany, $ticket, $request->header('X-Trace-Id') ?: $request->header('X-Request-Id'));
            $apiError = $this->catalogError($response);

            if ($apiError !== null) {
                return response()->json([
                    'status' => 'error',
                    'error' => $apiError,
                    'data' => [
                        'company_id' => $fiscalCompany->id,
                        'business_id' => $fiscalCompany->external_business_id,
                        'environment' => $fiscalCompany->environment,
                        'points_of_sale' => [],
                    ],
                ]);
            }

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'environment' => $fiscalCompany->environment,
                    'points_of_sale' => $this->normalizePointsOfSale($response),
                ],
            ]);
        } catch (FiscalException $exception) {
            return response()->json($exception->toPayload(), $exception->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unexpected fiscal points of sale query error.',
                'error_code' => 'unexpected_error',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function companyPayload(FiscalCompany $company): array
    {
        return [
            'id' => $company->id,
            'business_id' => $company->external_business_id,
            'cuit' => $company->cuit,
            'legal_name' => $company->legal_name,
            'fiscal_condition' => $company->fiscal_condition,
            'environment' => $company->environment,
            'enabled' => $company->enabled,
            'defaults' => [
                'point_of_sale' => $company->default_point_of_sale,
                'cbte_type' => $company->default_voucher_type,
            ],
            'onboarding_metadata' => $company->onboarding_metadata,
        ];
    }

    private function invalidateAccessTicketsIfEnvironmentChanged(FiscalCompany $company, string $previousEnvironment): void
    {
        if ($previousEnvironment === $company->environment) {
            return;
        }

        $company->accessTickets()->delete();
    }

    private function statusMessage(
        bool $enabled,
        bool $credentialConfigured,
        bool $credentialActive,
        bool $ticketConfigured,
        bool $ticketValid,
    ): string {
        if (! $enabled) {
            return 'Empresa fiscal deshabilitada en la API fiscal.';
        }

        if (! $credentialConfigured) {
            return 'Empresa fiscal creada; falta cargar o activar la credencial fiscal en la API.';
        }

        if (! $credentialActive) {
            return 'La credencial fiscal existe pero no esta activa en la API.';
        }

        if (! $ticketConfigured) {
            return 'Empresa fiscal operativa. El ticket de acceso se generara al consultar o emitir.';
        }

        if (! $ticketValid) {
            return 'Empresa fiscal operativa. El ticket de acceso vencido se renovara al consultar o emitir.';
        }

        return 'Empresa fiscal operativa.';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array{id: int, code: int, name: string|null}>
     */
    private function normalizeActivities(array $response): array
    {
        return collect($this->rowsFrom($response, [
            'ResultGet.Actividad',
            'Actividad',
            'activities',
        ]))
            ->map(function (array $row): ?array {
                $id = data_get($row, 'id') ?? data_get($row, 'Id') ?? data_get($row, 'code');

                if (! is_numeric($id)) {
                    return null;
                }

                return [
                    'id' => (int) $id,
                    'code' => (int) $id,
                    'name' => $this->stringOrNull(data_get($row, 'name') ?? data_get($row, 'Desc') ?? data_get($row, 'description')),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function normalizePointsOfSale(array $response): array
    {
        return collect($this->rowsFrom($response, [
            'ResultGet.PtoVenta',
            'PtoVenta',
            'points_of_sale',
        ]))
            ->map(function (array $row): ?array {
                $number = data_get($row, 'number')
                    ?? data_get($row, 'Nro')
                    ?? data_get($row, 'point_of_sale')
                    ?? data_get($row, 'id');

                if (! is_numeric($number)) {
                    return null;
                }

                $emissionType = $this->stringOrNull(
                    data_get($row, 'type')
                    ?? data_get($row, 'EmisionTipo')
                    ?? data_get($row, 'emission_type')
                );
                $blocked = data_get($row, 'blocked') ?? data_get($row, 'Bloqueado');

                return [
                    'id' => (int) $number,
                    'number' => (int) $number,
                    'type' => $emissionType,
                    'emission_type' => $emissionType,
                    'blocked' => $blocked === true || strtoupper((string) $blocked) === 'S',
                    'disabled_at' => $this->stringOrNull(data_get($row, 'disabled_at') ?? data_get($row, 'FchBaja')),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $paths
     * @return list<array<string, mixed>>
     */
    private function rowsFrom(array $response, array $paths): array
    {
        foreach ($paths as $path) {
            $value = data_get($response, $path);

            if (! is_array($value) || $value === []) {
                continue;
            }

            if (array_is_list($value)) {
                return array_values(array_filter($value, fn (mixed $row): bool => is_array($row)));
            }

            return [$value];
        }

        return array_is_list($response)
            ? array_values(array_filter($response, fn (mixed $row): bool => is_array($row)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{code: string, message: string, arca_code: string|null, arca_message: string|null}|null
     */
    private function catalogError(array $response): ?array
    {
        $error = data_get($response, 'Errors.Err');

        if (! is_array($error) || $error === []) {
            return null;
        }

        $row = array_is_list($error) ? ($error[0] ?? null) : $error;

        if (! is_array($row)) {
            return null;
        }

        return ArcaErrorMapper::mapArcaMessage(
            $this->stringOrNull(data_get($row, 'Code')),
            $this->stringOrNull(data_get($row, 'Msg')),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function credentialHasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
