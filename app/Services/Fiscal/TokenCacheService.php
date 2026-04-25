<?php

namespace App\Services\Fiscal;

use App\Models\AccessTicket;
use App\Models\FiscalCompany;
use App\Services\Fiscal\Contracts\WsaaClient;
use Illuminate\Support\Carbon;

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

        if ($ticket && $ticket->expiration_time->greaterThan($renewAfter)) {
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
                    'last_action' => $ticket ? 'renewed' : 'generated',
                    'last_action_at' => now()->toIso8601String(),
                    'wsaa_generation_time' => $newTicket->generationTime->toIso8601String(),
                    'wsaa_expiration_time' => $newTicket->expirationTime->toIso8601String(),
                ],
            ],
        );

        return $stored;
    }
}
