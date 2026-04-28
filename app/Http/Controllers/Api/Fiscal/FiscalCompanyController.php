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
                $existing->update($data);

                return response()->json(['data' => $this->companyPayload($existing->refresh())]);
            }

            $fiscalCompany = FiscalCompany::query()->updateOrCreate(
                ['external_business_id' => $data['external_business_id']],
                $data,
            );

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

            return response()->json([
                'data' => [
                    'id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'cuit' => $fiscalCompany->cuit,
                    'legal_name' => $fiscalCompany->legal_name,
                    'environment' => $fiscalCompany->environment,
                    'enabled' => $fiscalCompany->enabled,
                    'defaults' => [
                        'point_of_sale' => $fiscalCompany->default_point_of_sale,
                        'cbte_type' => $fiscalCompany->default_voucher_type,
                    ],
                    'credential' => [
                        'configured' => (bool) $credential,
                        'id' => $credential?->id,
                        'key_name' => $credential?->key_name,
                        'status' => $credential?->status,
                        'active' => (bool) $credential?->active,
                        'certificate_expires_at' => $credential?->certificate_expires_at?->toIso8601String(),
                    ],
                    'access_ticket' => [
                        'configured' => (bool) $ticket,
                        'valid' => (bool) ($ticket && $ticket->expiration_time->isFuture()),
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
            $activities = $this->wsfev1->activities($fiscalCompany, $ticket, $request->header('X-Trace-Id') ?: $request->header('X-Request-Id'));

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'environment' => $fiscalCompany->environment,
                    'activities' => $activities,
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
            $pointsOfSale = $this->wsfev1->pointsOfSale($fiscalCompany, $ticket, $request->header('X-Trace-Id') ?: $request->header('X-Request-Id'));

            return response()->json([
                'data' => [
                    'company_id' => $fiscalCompany->id,
                    'business_id' => $fiscalCompany->external_business_id,
                    'environment' => $fiscalCompany->environment,
                    'points_of_sale' => $pointsOfSale,
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
            'environment' => $company->environment,
            'enabled' => $company->enabled,
            'defaults' => [
                'point_of_sale' => $company->default_point_of_sale,
                'cbte_type' => $company->default_voucher_type,
            ],
            'onboarding_metadata' => $company->onboarding_metadata,
        ];
    }
}
