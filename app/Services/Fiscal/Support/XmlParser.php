<?php

namespace App\Services\Fiscal\Support;

use App\Exceptions\Fiscal\FiscalException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class XmlParser
{
    /**
     * @return array<string, mixed>|string
     */
    public function firstNode(string $xml, string $localName): array|string
    {
        $dom = $this->document($xml);
        $xpath = new DOMXPath($dom);

        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);

        if ($fault instanceof DOMElement) {
            $faultData = $this->nodeToArray($fault);
            $message = data_get($faultData, 'faultstring', 'SOAP fault returned by ARCA.');
            $code = data_get($faultData, 'faultcode');

            throw new FiscalException(ArcaErrorMapper::messageFor('soap_fault', (string) $message), 502, 'soap_fault', [
                'fault_code' => is_scalar($code) ? (string) $code : null,
                'fault' => $faultData,
            ]);
        }

        $node = $xpath->query('//*[local-name()="'.$localName.'"]')->item(0);

        if (! $node instanceof DOMElement) {
            throw new FiscalException("SOAP response node [$localName] was not found.", 502, 'soap_response_missing_node');
        }

        return $this->nodeToArray($node);
    }

    /**
     * @return array<string, mixed>
     */
    public function documentRoot(string $xml): array
    {
        return $this->nodeToArray($this->document($xml)->documentElement);
    }

    private function document(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new FiscalException(ArcaErrorMapper::messageFor('invalid_xml'), 502, 'invalid_xml', [
                'errors' => array_map(fn ($error) => trim($error->message), $errors),
            ]);
        }

        return $dom;
    }

    /**
     * @return array<string, mixed>|string
     */
    private function nodeToArray(DOMNode $node): array|string
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $children[$child->localName][] = $this->nodeToArray($child);
        }

        if ($children === []) {
            return trim($node->textContent);
        }

        $result = [];

        foreach ($children as $name => $values) {
            $result[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $result;
    }
}
