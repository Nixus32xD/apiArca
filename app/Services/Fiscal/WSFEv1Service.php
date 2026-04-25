<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Models\AccessTicket;
use App\Models\FiscalCompany;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use App\Services\Fiscal\Support\SoapXmlClient;

class WSFEv1Service implements Wsfev1Client
{
    public function __construct(
        private readonly SoapXmlClient $soapClient,
    ) {}

    public function authorize(
        FiscalCompany $company,
        AccessTicket $ticket,
        array $feCaeRequest,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): array {
        return $this->call(
            $company,
            'FECAESolicitar',
            $this->authXml($company, $ticket).$this->xmlElement('FeCAEReq', $feCaeRequest),
            'FECAESolicitarResult',
            $document,
            $traceId,
        );
    }

    public function lastAuthorized(
        FiscalCompany $company,
        AccessTicket $ticket,
        int $pointOfSale,
        int $voucherType,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): array {
        return $this->call(
            $company,
            'FECompUltimoAutorizado',
            $this->authXml($company, $ticket)
                .$this->xmlElement('PtoVta', $pointOfSale)
                .$this->xmlElement('CbteTipo', $voucherType),
            'FECompUltimoAutorizadoResult',
            $document,
            $traceId,
        );
    }

    public function consult(
        FiscalCompany $company,
        AccessTicket $ticket,
        int $pointOfSale,
        int $voucherType,
        int $voucherNumber,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): array {
        return $this->call(
            $company,
            'FECompConsultar',
            $this->authXml($company, $ticket)
                .$this->xmlElement('FeCompConsReq', [
                    'CbteTipo' => $voucherType,
                    'CbteNro' => $voucherNumber,
                    'PtoVta' => $pointOfSale,
                ]),
            'FECompConsultarResult',
            $document,
            $traceId,
        );
    }

    public function dummy(FiscalCompany $company, ?string $traceId = null): array
    {
        return $this->call($company, 'FEDummy', '', 'FEDummyResult', null, $traceId);
    }

    public function activities(FiscalCompany $company, AccessTicket $ticket, ?string $traceId = null): array
    {
        return $this->call(
            $company,
            'FEParamGetActividades',
            $this->authXml($company, $ticket),
            'FEParamGetActividadesResult',
            null,
            $traceId,
        );
    }

    public function pointsOfSale(FiscalCompany $company, AccessTicket $ticket, ?string $traceId = null): array
    {
        return $this->call(
            $company,
            'FEParamGetPtosVenta',
            $this->authXml($company, $ticket),
            'FEParamGetPtosVentaResult',
            null,
            $traceId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function call(
        FiscalCompany $company,
        string $operation,
        string $bodyXml,
        string $resultNode,
        ?FiscalDocument $document = null,
        ?string $traceId = null,
    ): array {
        $endpoint = $this->endpoint($company);
        $result = $this->soapClient->call(
            $endpoint,
            $operation,
            (string) config('fiscal.wsfev1.namespace'),
            $bodyXml,
            $resultNode,
            (string) config('fiscal.wsfev1.soap_action_base').$operation,
            $company,
            $document,
            $traceId,
        );

        if (! is_array($result)) {
            throw new FiscalException("WSFEv1 operation [$operation] returned an invalid response.", 502, 'wsfe_invalid_response');
        }

        return $result;
    }

    private function endpoint(FiscalCompany $company): string
    {
        $endpoint = config('fiscal.wsfev1.endpoints.'.$company->environment);

        if (! is_string($endpoint) || $endpoint === '') {
            throw new FiscalException('WSFEv1 endpoint is not configured.', 500, 'wsfe_endpoint_missing');
        }

        return $endpoint;
    }

    private function authXml(FiscalCompany $company, AccessTicket $ticket): string
    {
        return $this->xmlElement('Auth', [
            'Token' => $ticket->token,
            'Sign' => $ticket->sign,
            'Cuit' => $company->cuit,
        ]);
    }

    private function xmlElement(string $name, mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return implode('', array_map(fn ($item) => $this->xmlElement($name, $item), $value));
            }

            $children = '';

            foreach ($value as $childName => $childValue) {
                if ($childValue === null || $childValue === []) {
                    continue;
                }

                $children .= $this->xmlElement((string) $childName, $childValue);
            }

            return '<'.$name.'>'.$children.'</'.$name.'>';
        }

        return '<'.$name.'>'.$this->escape((string) $value).'</'.$name.'>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
