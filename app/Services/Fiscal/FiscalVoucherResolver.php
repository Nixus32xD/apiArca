<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;

class FiscalVoucherResolver
{
    public const VOUCHER_INVOICE_A = 1;

    public const VOUCHER_DEBIT_NOTE_A = 2;

    public const VOUCHER_CREDIT_NOTE_A = 3;

    public const VOUCHER_INVOICE_B = 6;

    public const VOUCHER_DEBIT_NOTE_B = 7;

    public const VOUCHER_CREDIT_NOTE_B = 8;

    public const VOUCHER_INVOICE_C = 11;

    public const VOUCHER_DEBIT_NOTE_C = 12;

    public const VOUCHER_CREDIT_NOTE_C = 13;

    public const DOC_TYPE_CUIT = 80;

    public const DOC_TYPE_DNI = 96;

    public const DOC_TYPE_CONSUMIDOR_FINAL = 99;

    public const IVA_RESPONSABLE_INSCRIPTO = 'responsable_inscripto';

    public const IVA_MONOTRIBUTO = 'monotributo';

    public const IVA_CONSUMIDOR_FINAL = 'consumidor_final';

    public const IVA_EXENTO = 'exento';

    public const KIND_INVOICE = 'invoice';

    public const KIND_DEBIT_NOTE = 'debit_note';

    public const KIND_CREDIT_NOTE = 'credit_note';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(FiscalCompany $company, array $payload): array
    {
        $issuerCondition = $this->issuerCondition($company);
        $receiver = $this->receiver($payload['customer'] ?? []);
        $invoiceMode = strtolower(trim((string) ($payload['invoice_mode'] ?? 'manual')));
        $documentKind = $this->documentKind($payload);
        $voucherType = $invoiceMode === 'auto'
            ? $this->automaticVoucherType($issuerCondition, $receiver['iva_condition'], $documentKind)
            : $this->manualVoucherType($company, $payload);

        $this->assertVoucherAllowed($voucherType, $issuerCondition, $receiver);
        $documentKind = self::documentKindForVoucher($voucherType);

        return [
            'invoice_mode' => $invoiceMode === 'auto' ? 'auto' : 'manual',
            'document_type' => self::documentTypeForVoucher($voucherType),
            'document_kind' => $documentKind,
            'voucher_type' => $voucherType,
            'issuer' => [
                'iva_condition' => $issuerCondition,
            ],
            'customer' => $receiver,
        ];
    }

    private function issuerCondition(FiscalCompany $company): string
    {
        $condition = $this->normalizeCondition($company->fiscal_condition);

        if (! in_array($condition, [
            self::IVA_MONOTRIBUTO,
            self::IVA_RESPONSABLE_INSCRIPTO,
            self::IVA_EXENTO,
        ], true)) {
            throw new FiscalException('Issuer fiscal condition is required.', 422, 'issuer_fiscal_condition_required', [
                'company_id' => $company->id,
            ]);
        }

        return $condition;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function manualVoucherType(FiscalCompany $company, array $payload): int
    {
        $voucherType = $payload['cbte_type'] ?? $company->default_voucher_type;

        if (! $voucherType) {
            throw new FiscalException('Voucher type is required.', 422, 'voucher_type_required');
        }

        return (int) $voucherType;
    }

    private function automaticVoucherType(string $issuerCondition, string $receiverCondition, string $documentKind): int
    {
        if (in_array($issuerCondition, [self::IVA_MONOTRIBUTO, self::IVA_EXENTO], true)) {
            return $this->voucherForClass('C', $documentKind);
        }

        return $this->voucherForClass(
            $receiverCondition === self::IVA_RESPONSABLE_INSCRIPTO ? 'A' : 'B',
            $documentKind,
        );
    }

    /**
     * @param  array<string, mixed>  $receiver
     */
    private function assertVoucherAllowed(int $voucherType, string $issuerCondition, array $receiver): void
    {
        $voucherClass = self::voucherClass($voucherType);

        if ($voucherClass === 'A') {
            if ($issuerCondition !== self::IVA_RESPONSABLE_INSCRIPTO) {
                throw new FiscalException('Comprobantes A are only allowed for Responsable Inscripto issuers.', 422, 'voucher_a_issuer_not_ri');
            }

            if ($receiver['iva_condition'] !== self::IVA_RESPONSABLE_INSCRIPTO) {
                throw new FiscalException('Comprobantes A require a Responsable Inscripto receiver.', 422, 'voucher_a_receiver_not_ri');
            }

            if ((int) $receiver['doc_type'] !== self::DOC_TYPE_CUIT || ! $this->isValidCuit((string) $receiver['document_number'])) {
                throw new FiscalException('Comprobantes A require a valid receiver CUIT.', 422, 'voucher_a_receiver_cuit_required');
            }

            return;
        }

        if ($voucherClass === 'B') {
            if ($issuerCondition !== self::IVA_RESPONSABLE_INSCRIPTO) {
                throw new FiscalException('Comprobantes B are only allowed for Responsable Inscripto issuers.', 422, 'voucher_b_issuer_not_ri');
            }

            if ($receiver['iva_condition'] === self::IVA_RESPONSABLE_INSCRIPTO) {
                throw new FiscalException('Comprobantes B are not allowed for Responsable Inscripto receivers.', 422, 'voucher_b_receiver_ri');
            }

            return;
        }

        if ($voucherClass === 'C') {
            if ($issuerCondition === self::IVA_RESPONSABLE_INSCRIPTO) {
                throw new FiscalException('Comprobantes C are not allowed for Responsable Inscripto issuers.', 422, 'voucher_c_issuer_ri');
            }

            return;
        }

        throw new FiscalException('Unsupported fiscal voucher type.', 422, 'unsupported_voucher_type', [
            'voucher_type' => $voucherType,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function receiver(mixed $customer): array
    {
        $customer = is_array($customer) ? $customer : [];
        $condition = $this->receiverCondition($customer);
        $documentType = $this->receiverDocumentType($customer);
        $documentNumber = $this->receiverDocumentNumber($customer);

        if ($documentType === 'CONSUMIDOR_FINAL' || $documentNumber === '') {
            $documentType = 'CONSUMIDOR_FINAL';
            $documentNumber = '0';
            $condition = $condition ?: self::IVA_CONSUMIDOR_FINAL;
        }

        $docType = match ($documentType) {
            'CUIT' => self::DOC_TYPE_CUIT,
            'DNI' => self::DOC_TYPE_DNI,
            default => self::DOC_TYPE_CONSUMIDOR_FINAL,
        };

        if ($condition === '') {
            $condition = $docType === self::DOC_TYPE_CONSUMIDOR_FINAL
                ? self::IVA_CONSUMIDOR_FINAL
                : self::IVA_CONSUMIDOR_FINAL;
        }

        if (in_array($condition, [
            self::IVA_RESPONSABLE_INSCRIPTO,
            self::IVA_MONOTRIBUTO,
            self::IVA_EXENTO,
        ], true)) {
            if ($docType !== self::DOC_TYPE_CUIT || ! $this->isValidCuit($documentNumber)) {
                throw new FiscalException('Receiver CUIT is required for the selected IVA condition.', 422, 'receiver_cuit_required', [
                    'iva_condition' => $condition,
                ]);
            }
        }

        if ($docType === self::DOC_TYPE_DNI && ! preg_match('/^\d{7,8}$/', $documentNumber)) {
            throw new FiscalException('Receiver DNI must have 7 or 8 digits.', 422, 'receiver_dni_invalid');
        }

        $docNumber = $docType === self::DOC_TYPE_CONSUMIDOR_FINAL ? 0 : (int) $documentNumber;

        return [
            'doc_type' => $docType,
            'doc_number' => $docNumber,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'name' => $this->nullableString($customer['name'] ?? null)
                ?? ($docType === self::DOC_TYPE_CONSUMIDOR_FINAL ? 'Consumidor Final' : null),
            'iva_condition' => $condition,
            'tax_condition' => $condition,
            'tax_condition_id' => $this->taxConditionId($condition),
            'address' => $this->nullableString($customer['address'] ?? null),
            'email' => $this->nullableString($customer['email'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function receiverCondition(array $customer): string
    {
        $condition = $this->normalizeCondition(
            $customer['iva_condition']
                ?? $customer['tax_condition']
                ?? null
        );

        if ($condition !== '') {
            return $condition;
        }

        $fromId = $this->conditionFromTaxConditionId($customer['tax_condition_id'] ?? null);

        if ($fromId !== '') {
            return $fromId;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function receiverDocumentType(array $customer): string
    {
        $documentType = strtoupper(trim((string) ($customer['document_type'] ?? '')));

        if ($documentType === 'CONSUMIDOR_FINAL') {
            return 'CONSUMIDOR_FINAL';
        }

        if (in_array($documentType, ['CUIT', 'DNI'], true)) {
            return $documentType;
        }

        $docType = isset($customer['doc_type']) ? (int) $customer['doc_type'] : null;

        return match ($docType) {
            self::DOC_TYPE_CUIT => 'CUIT',
            self::DOC_TYPE_DNI => 'DNI',
            default => 'CONSUMIDOR_FINAL',
        };
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function receiverDocumentNumber(array $customer): string
    {
        $number = $customer['document_number']
            ?? $customer['doc_number']
            ?? null;

        return preg_replace('/\D+/', '', (string) $number) ?? '';
    }

    public static function documentKindForVoucher(int $voucherType): string
    {
        return match ($voucherType) {
            self::VOUCHER_DEBIT_NOTE_A, self::VOUCHER_DEBIT_NOTE_B, self::VOUCHER_DEBIT_NOTE_C => self::KIND_DEBIT_NOTE,
            self::VOUCHER_CREDIT_NOTE_A, self::VOUCHER_CREDIT_NOTE_B, self::VOUCHER_CREDIT_NOTE_C => self::KIND_CREDIT_NOTE,
            default => self::KIND_INVOICE,
        };
    }

    public static function voucherClass(int $voucherType): ?string
    {
        return match ($voucherType) {
            self::VOUCHER_INVOICE_A, self::VOUCHER_DEBIT_NOTE_A, self::VOUCHER_CREDIT_NOTE_A => 'A',
            self::VOUCHER_INVOICE_B, self::VOUCHER_DEBIT_NOTE_B, self::VOUCHER_CREDIT_NOTE_B => 'B',
            self::VOUCHER_INVOICE_C, self::VOUCHER_DEBIT_NOTE_C, self::VOUCHER_CREDIT_NOTE_C => 'C',
            default => null,
        };
    }

    public static function isClassC(int $voucherType): bool
    {
        return self::voucherClass($voucherType) === 'C';
    }

    public static function isCreditNote(int $voucherType): bool
    {
        return self::documentKindForVoucher($voucherType) === self::KIND_CREDIT_NOTE;
    }

    public static function requiresAssociatedVoucher(int $voucherType): bool
    {
        return in_array(self::documentKindForVoucher($voucherType), [self::KIND_CREDIT_NOTE, self::KIND_DEBIT_NOTE], true);
    }

    public static function signForVoucher(int $voucherType): int
    {
        return self::isCreditNote($voucherType) ? -1 : 1;
    }

    public static function documentTypeForVoucher(int $voucherType): string
    {
        return match ($voucherType) {
            self::VOUCHER_INVOICE_A => 'invoice_a',
            self::VOUCHER_DEBIT_NOTE_A => 'debit_note_a',
            self::VOUCHER_CREDIT_NOTE_A => 'credit_note_a',
            self::VOUCHER_INVOICE_B => 'invoice_b',
            self::VOUCHER_DEBIT_NOTE_B => 'debit_note_b',
            self::VOUCHER_CREDIT_NOTE_B => 'credit_note_b',
            self::VOUCHER_INVOICE_C => 'invoice_c',
            self::VOUCHER_DEBIT_NOTE_C => 'debit_note_c',
            self::VOUCHER_CREDIT_NOTE_C => 'credit_note_c',
            default => 'invoice',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function documentKind(array $payload): string
    {
        $kind = strtolower(trim((string) ($payload['document_kind'] ?? '')));

        if (in_array($kind, [self::KIND_INVOICE, self::KIND_DEBIT_NOTE, self::KIND_CREDIT_NOTE], true)) {
            return $kind;
        }

        $documentType = strtolower(trim((string) ($payload['document_type'] ?? '')));

        if (str_contains($documentType, 'credit_note') || str_contains($documentType, 'nota_credito')) {
            return self::KIND_CREDIT_NOTE;
        }

        if (str_contains($documentType, 'debit_note') || str_contains($documentType, 'nota_debito')) {
            return self::KIND_DEBIT_NOTE;
        }

        if (isset($payload['cbte_type']) && is_numeric($payload['cbte_type'])) {
            return self::documentKindForVoucher((int) $payload['cbte_type']);
        }

        return self::KIND_INVOICE;
    }

    private function voucherForClass(string $voucherClass, string $documentKind): int
    {
        return match ($voucherClass.'_'.$documentKind) {
            'A_'.self::KIND_DEBIT_NOTE => self::VOUCHER_DEBIT_NOTE_A,
            'A_'.self::KIND_CREDIT_NOTE => self::VOUCHER_CREDIT_NOTE_A,
            'B_'.self::KIND_DEBIT_NOTE => self::VOUCHER_DEBIT_NOTE_B,
            'B_'.self::KIND_CREDIT_NOTE => self::VOUCHER_CREDIT_NOTE_B,
            'C_'.self::KIND_DEBIT_NOTE => self::VOUCHER_DEBIT_NOTE_C,
            'C_'.self::KIND_CREDIT_NOTE => self::VOUCHER_CREDIT_NOTE_C,
            'A_'.self::KIND_INVOICE => self::VOUCHER_INVOICE_A,
            'B_'.self::KIND_INVOICE => self::VOUCHER_INVOICE_B,
            default => self::VOUCHER_INVOICE_C,
        };
    }

    private function normalizeCondition(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'iva_responsable_inscripto', 'responsable_inscripto', 'ri' => self::IVA_RESPONSABLE_INSCRIPTO,
            'monotributo', 'monotributista', 'responsable_monotributo' => self::IVA_MONOTRIBUTO,
            'iva_exento', 'exento' => self::IVA_EXENTO,
            'consumidor_final', 'cf', 'final' => self::IVA_CONSUMIDOR_FINAL,
            default => '',
        };
    }

    private function conditionFromTaxConditionId(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '';
        }

        return match ((int) $value) {
            1 => self::IVA_RESPONSABLE_INSCRIPTO,
            4 => self::IVA_EXENTO,
            5 => self::IVA_CONSUMIDOR_FINAL,
            6, 13, 16 => self::IVA_MONOTRIBUTO,
            default => '',
        };
    }

    private function taxConditionId(string $condition): int
    {
        return match ($condition) {
            self::IVA_RESPONSABLE_INSCRIPTO => 1,
            self::IVA_EXENTO => 4,
            self::IVA_MONOTRIBUTO => 6,
            default => 5,
        };
    }

    private function isValidCuit(string $value): bool
    {
        if (! preg_match('/^\d{11}$/', $value)) {
            return false;
        }

        if (! in_array(substr($value, 0, 2), ['20', '23', '24', '27', '30', '33', '34'], true)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $value[$index]) * $weight;
        }

        $checkDigit = 11 - ($sum % 11);
        if ($checkDigit === 11) {
            $checkDigit = 0;
        } elseif ($checkDigit === 10) {
            $checkDigit = 9;
        }

        return $checkDigit === (int) $value[10];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
