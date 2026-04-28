<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\FiscalCompany;
use App\Models\FiscalCredential;
use App\Services\Fiscal\Contracts\WsaaClient;
use App\Services\Fiscal\Data\AccessTicketData;
use App\Services\Fiscal\Support\ArcaErrorMapper;
use App\Services\Fiscal\Support\SoapXmlClient;
use App\Services\Fiscal\Support\XmlParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class WSAAService implements WsaaClient
{
    private const WSAA_NAMESPACE = 'http://wsaa.view.sua.dvadac.desein.afip.gov';

    public function __construct(
        private readonly SoapXmlClient $soapClient,
        private readonly XmlParser $xmlParser,
    ) {}

    public function login(FiscalCompany $company, FiscalCredential $credential, string $service): AccessTicketData
    {
        $endpoint = $this->endpoint($company);
        $tra = $this->buildLoginTicketRequest($company, $service);
        $cms = $this->signCms($tra, $credential);

        $result = $this->soapClient->call(
            $endpoint,
            'loginCms',
            self::WSAA_NAMESPACE,
            '<in0>'.$this->escape($cms).'</in0>',
            'loginCmsReturn',
            '',
            $company,
            null,
        );

        if (! is_string($result) || trim($result) === '') {
            throw new FiscalException(ArcaErrorMapper::messageFor('wsaa_empty_ticket'), 502, 'wsaa_empty_ticket');
        }

        $ticketXml = html_entity_decode($result, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $ticket = $this->xmlParser->documentRoot($ticketXml);

        $token = data_get($ticket, 'credentials.token');
        $sign = data_get($ticket, 'credentials.sign');
        $generationTime = data_get($ticket, 'header.generationTime');
        $expirationTime = data_get($ticket, 'header.expirationTime');

        if (! is_string($token) || ! is_string($sign) || ! is_string($generationTime) || ! is_string($expirationTime)) {
            throw new FiscalException(ArcaErrorMapper::messageFor('wsaa_invalid_ticket'), 502, 'wsaa_invalid_ticket', [
                'response' => $ticket,
            ]);
        }

        return new AccessTicketData(
            token: $token,
            sign: $sign,
            generationTime: CarbonImmutable::parse($generationTime),
            expirationTime: CarbonImmutable::parse($expirationTime),
            rawResponse: $ticket,
        );
    }

    private function endpoint(FiscalCompany $company): string
    {
        $endpoint = config('fiscal.wsaa.endpoints.'.$company->environment);

        if (! is_string($endpoint) || $endpoint === '') {
            throw new FiscalException('WSAA endpoint is not configured.', 500, 'wsaa_endpoint_missing');
        }

        return $endpoint;
    }

    private function buildLoginTicketRequest(FiscalCompany $company, string $service): string
    {
        $now = CarbonImmutable::now();
        $ttl = (int) config('fiscal.wsaa.ticket_ttl_minutes', 720);
        $destination = config('fiscal.wsaa.destination_dn.'.$company->environment);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<loginTicketRequest version="1.0">'
            .'<header>'
            .'<destination>'.$this->escape((string) $destination).'</destination>'
            .'<uniqueId>'.$now->timestamp.'</uniqueId>'
            .'<generationTime>'.$now->subMinute()->toIso8601String().'</generationTime>'
            .'<expirationTime>'.$now->addMinutes($ttl)->toIso8601String().'</expirationTime>'
            .'</header>'
            .'<service>'.$this->escape($service).'</service>'
            .'</loginTicketRequest>';
    }

    private function signCms(string $traXml, FiscalCredential $credential): string
    {
        $directory = storage_path('app/private/fiscal-tmp');
        File::ensureDirectoryExists($directory, 0700);

        $id = (string) Str::uuid();
        $traPath = $directory.DIRECTORY_SEPARATOR.$id.'.xml';
        $certPath = $directory.DIRECTORY_SEPARATOR.$id.'.crt';
        $cmsPath = $directory.DIRECTORY_SEPARATOR.$id.'.cms';
        $smimePath = $directory.DIRECTORY_SEPARATOR.$id.'.smime';

        file_put_contents($traPath, $traXml);
        file_put_contents($certPath, $credential->certificate);

        $privateKey = openssl_pkey_get_private($credential->private_key, $credential->passphrase ?: null);

        if ($privateKey === false) {
            $this->deleteFiles([$traPath, $certPath, $cmsPath, $smimePath]);

            throw new FiscalException(ArcaErrorMapper::messageFor('private_key_invalid'), 409, 'private_key_invalid');
        }

        try {
            if (function_exists('openssl_cms_sign')) {
                $flags = defined('OPENSSL_CMS_BINARY') ? OPENSSL_CMS_BINARY : 0;
                $encoding = defined('OPENSSL_ENCODING_DER') ? OPENSSL_ENCODING_DER : 2;

                $signed = openssl_cms_sign($traPath, $cmsPath, 'file://'.$certPath, $privateKey, [], $flags, $encoding);

                if (! $signed) {
                    throw new FiscalException(ArcaErrorMapper::messageFor('cms_sign_failed'), 500, 'cms_sign_failed');
                }

                return base64_encode((string) file_get_contents($cmsPath));
            }

            $signed = openssl_pkcs7_sign($traPath, $smimePath, 'file://'.$certPath, $privateKey, [], PKCS7_BINARY);

            if (! $signed) {
                throw new FiscalException(ArcaErrorMapper::messageFor('cms_sign_failed'), 500, 'cms_sign_failed');
            }

            return $this->extractSmimeBase64((string) file_get_contents($smimePath));
        } catch (FiscalException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FiscalException(ArcaErrorMapper::messageFor('cms_sign_unexpected_error'), 500, 'cms_sign_unexpected_error', [], $exception);
        } finally {
            $this->deleteFiles([$traPath, $certPath, $cmsPath, $smimePath]);
        }
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function extractSmimeBase64(string $smime): string
    {
        $parts = preg_split("/\R\R/", $smime, 2);
        $body = $parts[1] ?? $smime;

        return preg_replace('/\s+/', '', $body) ?? '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
