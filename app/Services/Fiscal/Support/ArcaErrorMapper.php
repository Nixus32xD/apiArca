<?php

namespace App\Services\Fiscal\Support;

use App\Exceptions\Fiscal\FiscalException;
use Illuminate\Support\Str;

class ArcaErrorMapper
{
    public const HTTP_502_MESSAGE = 'ARCA devolvió un error interno o de infraestructura. Puede ser temporal. Se debe consultar el estado del comprobante antes de reintentar.';

    public const HTTP_504_MESSAGE = 'La conexión con ARCA agotó el tiempo de espera. No se sabe si el comprobante fue procesado. Se debe consultar el comprobante antes de volver a emitir.';

    public const AUTH_MESSAGE = 'Error de autenticación con ARCA. Revisar certificado, clave privada, token WSAA, CUIT representada y servicio habilitado.';

    public const NUMBERING_MESSAGE = 'El número de comprobante no respeta la correlatividad o ya pudo haber sido procesado por ARCA.';

    public static function messageForException(FiscalException $exception): string
    {
        $statusCode = self::httpStatusFromContext($exception->context());

        if ($exception->errorCode() === 'arca_http_error' && $statusCode !== null) {
            return self::messageForHttpStatus($statusCode, $exception->getMessage());
        }

        return self::messageFor((string) $exception->errorCode(), $exception->getMessage());
    }

    public static function messageForHttpStatus(int $statusCode, ?string $fallback = null): string
    {
        return match ($statusCode) {
            502 => self::HTTP_502_MESSAGE,
            504 => self::HTTP_504_MESSAGE,
            default => $fallback ?: 'ARCA devolvió un error HTTP al procesar la operación fiscal.',
        };
    }

    public static function messageFor(string $code, ?string $fallback = null): string
    {
        return match ($code) {
            'arca_http_error' => $fallback ?: 'ARCA devolvió un error HTTP al procesar la operación fiscal.',
            'arca_timeout' => self::HTTP_504_MESSAGE,
            'arca_unexpected_error' => 'Ocurrió un error inesperado al comunicarse con ARCA. Revisar logs técnicos antes de reintentar.',
            'soap_fault' => $fallback ?: 'ARCA devolvió un SOAP fault. Revisar el detalle técnico y el estado del comprobante antes de reintentar.',
            'invalid_xml' => 'ARCA devolvió una respuesta XML inválida. Se debe consultar el comprobante antes de volver a emitir.',
            'wsfe_invalid_response' => 'ARCA devolvió una respuesta inválida para WSFEv1. Se debe consultar el comprobante antes de volver a emitir.',
            'wsaa_invalid_ticket',
            'wsaa_empty_ticket',
            'token_invalid',
            'signature_invalid',
            'private_key_invalid',
            'cms_sign_failed',
            'cms_sign_unexpected_error' => self::AUTH_MESSAGE,
            'cuit_unauthorized' => 'La CUIT representada no está autorizada para operar el servicio fiscal solicitado.',
            'numbering_error',
            'voucher_duplicate' => self::NUMBERING_MESSAGE,
            'amount_error' => 'ARCA rechazó el comprobante por inconsistencias en importes, netos, IVA, tributos o totales.',
            'receiver_data_error' => 'ARCA rechazó el comprobante por datos inválidos o incompletos del receptor.',
            'arca_voucher_not_found' => 'ARCA no registra el comprobante consultado. Se puede reintentar de forma segura con la misma numeración.',
            default => $fallback ?: 'Error fiscal no clasificado.',
        };
    }

    /**
     * @return array{code: string, message: string, arca_code: string|null, arca_message: string|null}
     */
    public static function mapArcaMessage(?string $arcaCode, ?string $arcaMessage): array
    {
        $code = self::classifyArcaMessage($arcaCode, $arcaMessage)
            ?? ($arcaCode ? 'arca_error_'.$arcaCode : 'arca_error');

        return [
            'code' => $code,
            'message' => self::messageFor($code, $arcaMessage),
            'arca_code' => $arcaCode,
            'arca_message' => $arcaMessage,
        ];
    }

    /**
     * @param  array<int, array<string, string|null>>  $messages
     */
    public static function containsVoucherNotFound(array $messages): bool
    {
        foreach ($messages as $message) {
            if (($message['code'] ?? null) === 'arca_voucher_not_found') {
                return true;
            }
        }

        return false;
    }

    public static function shouldMarkDocumentUncertain(FiscalException $exception): bool
    {
        $code = $exception->errorCode();
        $statusCode = self::httpStatusFromContext($exception->context());

        if ($code === 'arca_timeout') {
            return true;
        }

        if ($code === 'arca_http_error' && in_array($statusCode, [502, 504], true)) {
            return true;
        }

        return in_array($code, [
            'soap_fault',
            'invalid_xml',
            'wsfe_invalid_response',
            'arca_unexpected_error',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function httpStatusFromContext(array $context): ?int
    {
        $statusCode = data_get($context, 'status_code');

        return is_numeric($statusCode) ? (int) $statusCode : null;
    }

    private static function classifyArcaMessage(?string $arcaCode, ?string $arcaMessage): ?string
    {
        $text = self::normalize(trim(($arcaCode ?? '').' '.($arcaMessage ?? '')));

        if ($text === '') {
            return null;
        }

        if (self::containsAny($text, ['token', 'ticket de acceso', 'autenticacion', 'credencial'])) {
            return 'token_invalid';
        }

        if (self::containsAny($text, ['firma', 'sign'])) {
            return 'signature_invalid';
        }

        if (str_contains($text, 'cuit') && self::containsAny($text, ['autoriz', 'habilit', 'represent'])) {
            return 'cuit_unauthorized';
        }

        if (self::containsAny($text, ['duplicad', 'ya existe', 'ya fue informado', 'ya fue procesado'])) {
            return 'voucher_duplicate';
        }

        if (self::containsAny($text, ['correlativ', 'numeracion', 'numero de comprobante', 'cbte nro'])) {
            return 'numbering_error';
        }

        if (self::containsAny($text, ['importe', 'imp total', 'impneto', 'impiva', 'iva', 'tributo', 'total'])) {
            return 'amount_error';
        }

        if (self::containsAny($text, ['receptor', 'doctipo', 'docnro', 'documento del comprador', 'condicion iva'])) {
            return 'receiver_data_error';
        }

        if (
            self::containsAny($text, ['no existe', 'inexistente', 'no encontrado', 'no registra'])
            && self::containsAny($text, ['comprobante', 'cbte'])
        ) {
            return 'arca_voucher_not_found';
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        return (string) Str::of($value)->lower()->ascii();
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
