<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Models\FiscalSequenceReservation;
use App\Services\Fiscal\Contracts\Wsfev1Client;

class FiscalSequenceRecoveryService
{
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly TokenCacheService $tokenCache,
        private readonly Wsfev1Client $wsfev1,
    ) {}

    /**
     * Compare a local sequence with ARCA without creating or changing a
     * document. A later issuance performs its own fresh ARCA lookup while the
     * sequence lock is held, so this endpoint is safe to use as a recovery
     * diagnostic after restoring data.
     *
     * @return array<string, bool|int|string|null>
     */
    public function reconcile(FiscalCompany $company, int $pointOfSale, int $voucherType, ?string $traceId = null): array
    {
        $this->credentialStore->activeFor($company);
        $ticket = $this->tokenCache->get($company);
        $response = $this->wsfev1->lastAuthorized($company, $ticket, $pointOfSale, $voucherType, null, $traceId);
        $arcaLastNumber = data_get($response, 'CbteNro');

        if (! is_numeric($arcaLastNumber) || (int) $arcaLastNumber < 0) {
            throw new FiscalException(
                'ARCA no devolvió un último comprobante válido; la secuencia no se modificó.',
                502,
                'arca_last_authorized_invalid',
            );
        }

        $localDocumentNumber = FiscalDocument::query()
            ->where('fiscal_company_id', $company->id)
            ->where('point_of_sale', $pointOfSale)
            ->where('voucher_type', $voucherType)
            ->max('document_number');

        $localReservationNumber = FiscalSequenceReservation::query()
            ->where('fiscal_company_id', $company->id)
            ->where('point_of_sale', $pointOfSale)
            ->where('voucher_type', $voucherType)
            ->max('document_number');

        $unresolvedDocument = FiscalDocument::query()
            ->where('fiscal_company_id', $company->id)
            ->where('point_of_sale', $pointOfSale)
            ->where('voucher_type', $voucherType)
            ->whereNotNull('document_number')
            ->whereIn('status', ['processing', 'uncertain'])
            ->latest('id')
            ->first(['id', 'document_number']);

        $localHighestNumber = max((int) ($localDocumentNumber ?? 0), (int) ($localReservationNumber ?? 0));
        $arcaLastNumber = (int) $arcaLastNumber;
        $requiresReview = $localHighestNumber > $arcaLastNumber || $unresolvedDocument !== null;

        return [
            'environment' => $company->environment,
            'point_of_sale' => $pointOfSale,
            'voucher_type' => $voucherType,
            'arca_last_authorized_number' => $arcaLastNumber,
            'local_document_number' => $localDocumentNumber === null ? null : (int) $localDocumentNumber,
            'local_reservation_number' => $localReservationNumber === null ? null : (int) $localReservationNumber,
            'local_highest_number' => $localHighestNumber === 0 ? null : $localHighestNumber,
            'unresolved_document_id' => $unresolvedDocument?->id,
            'unresolved_document_number' => $unresolvedDocument?->document_number,
            'status' => $unresolvedDocument !== null
                ? 'local_unresolved_requires_reconcile'
                : ($localHighestNumber > $arcaLastNumber
                    ? 'local_ahead_requires_review'
                    : ($localHighestNumber === $arcaLastNumber ? 'aligned' : 'arca_ahead')),
            'safe_to_issue' => ! $requiresReview,
            'next_number' => $requiresReview ? null : $arcaLastNumber + 1,
            'requires_review' => $requiresReview,
        ];
    }
}
