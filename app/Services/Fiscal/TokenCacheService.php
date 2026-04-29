<?php

namespace App\Services\Fiscal;

use App\Models\AccessTicket;
use App\Models\FiscalCompany;
use App\Services\Fiscal\Contracts\WsaaClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TokenCacheService
{
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly WsaaClient $wsaaClient,
    ) {}

    public function get(FiscalCompany $company, ?string $service = null): AccessTicket
    {
        $service ??= (string) config('fiscal.wsaa.service', 'wsfe');
        $renewAfter = Carbon::now()->addMinutes((int) config('fiscal.wsaa.renew_within_minutes', 30));

        /** @var AccessTicket|null $ticket */
        $ticket = $company->accessTickets()
            ->where('service', $service)
            ->first();

        if ($ticket && $this->isReusableTicket($ticket, $company, $renewAfter)) {
            $ticket->forceFill([
                'reused_count' => $ticket->reused_count + 1,
                'last_used_at' => now(),
                'metadata' => array_merge($ticket->metadata ?? [], [
                    'last_action' => 'reused',
                    'last_action_at' => now()->toIso8601String(),
                ]),
            ])->save();

            return $ticket->refresh();
        }

        return $this->renewTicketWithLock($company, $service, $renewAfter);
    }

    private function renewTicketWithLock(FiscalCompany $company, string $service, Carbon $renewAfter): AccessTicket
    {
        $callback = function () use ($company, $service, $renewAfter): AccessTicket {
            $current = $company->accessTickets()
                ->where('service', $service)
                ->first();

            if ($current && $this->isReusableTicket($current, $company, $renewAfter)) {
                $current->forceFill([
                    'reused_count' => $current->reused_count + 1,
                    'last_used_at' => now(),
                    'metadata' => array_merge($current->metadata ?? [], [
                        'last_action' => 'reused_after_lock',
                        'last_action_at' => now()->toIso8601String(),
                    ]),
                ])->save();

                return $current->refresh();
            }

            $credential = $this->credentialStore->activeFor($company);
            $newTicket = $this->wsaaClient->login($company, $credential, $service);

            /** @var AccessTicket $stored */
            $stored = AccessTicket::query()->updateOrCreate(
                [
                    'fiscal_company_id' => $company->id,
                    'service' => $service,
                ],
                [
                    'token' => $newTicket->token,
                    'sign' => $newTicket->sign,
                    'generation_time' => $newTicket->generationTime,
                    'expiration_time' => $newTicket->expirationTime,
                    'last_used_at' => now(),
                    'metadata' => [
                        'environment' => $company->environment,
                        'cuit' => $company->cuit,
                        'service' => $service,
                        'last_action' => $current ? 'renewed' : 'generated',
                        'last_action_at' => now()->toIso8601String(),
                        'wsaa_generation_time' => $newTicket->generationTime->toIso8601String(),
                        'wsaa_expiration_time' => $newTicket->expirationTime->toIso8601String(),
                    ],
                ],
            );

            return $stored;
        };

        $lockKey = sprintf('fiscal:wsaa:ticket:%s:%s:%s', $company->id, $company->environment, $service);

        try {
            $lock = Cache::lock($lockKey, 15);

            return $lock->block(5, $callback);
        } catch (Throwable) {
            return $callback();
        }
    }

    private function isReusableTicket(AccessTicket $ticket, FiscalCompany $company, Carbon $renewAfter): bool
    {
        if (! $ticket->expiration_time->greaterThan($renewAfter)) {
            return false;
        }

        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $metadataEnvironment = $metadata['environment'] ?? null;
        $metadataCuit = $metadata['cuit'] ?? null;

        if (is_string($metadataEnvironment) && $metadataEnvironment !== $company->environment) {
            return false;
        }

        if (is_string($metadataCuit) && $metadataCuit !== $company->cuit) {
            return false;
        }

        return true;
    }
}
