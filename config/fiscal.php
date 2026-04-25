<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Internal API authentication
    |--------------------------------------------------------------------------
    |
    | Configure one or more tokens for the SaaS client. Values may be stored as
    | plain secrets or as sha256:<hash>. Plain secrets are compared with
    | hash_equals and should only live in the environment.
    |
    */
    'api_tokens' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FISCAL_API_TOKENS', ''))
    ))),

    'soap' => [
        'timeout' => (int) env('FISCAL_SOAP_TIMEOUT', 30),
        'connect_timeout' => (int) env('FISCAL_SOAP_CONNECT_TIMEOUT', 10),
    ],

    'openssl' => [
        'config_path' => env('FISCAL_OPENSSL_CONF'),
        'private_key_bits' => (int) env('FISCAL_OPENSSL_PRIVATE_KEY_BITS', 2048),
    ],

    'wsaa' => [
        'service' => env('FISCAL_WSAA_SERVICE', 'wsfe'),
        'ticket_ttl_minutes' => (int) env('FISCAL_WSAA_TICKET_TTL_MINUTES', 720),
        'renew_within_minutes' => (int) env('FISCAL_WSAA_RENEW_WITHIN_MINUTES', 30),
        'endpoints' => [
            'testing' => env('FISCAL_WSAA_TESTING_URL', 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'),
            'production' => env('FISCAL_WSAA_PRODUCTION_URL', 'https://wsaa.afip.gov.ar/ws/services/LoginCms'),
        ],
        'destination_dn' => [
            'testing' => 'cn=wsaahomo,o=afip,c=ar,serialNumber=CUIT 33693450239',
            'production' => 'cn=wsaa,o=afip,c=ar,serialNumber=CUIT 33693450239',
        ],
    ],

    'wsfev1' => [
        'namespace' => 'http://ar.gov.afip.dif.FEV1/',
        'soap_action_base' => 'http://ar.gov.afip.dif.FEV1/',
        'endpoints' => [
            'testing' => env('FISCAL_WSFEV1_TESTING_URL', 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'),
            'production' => env('FISCAL_WSFEV1_PRODUCTION_URL', 'https://servicios1.afip.gov.ar/wsfev1/service.asmx'),
        ],
        'wsdls' => [
            'testing' => env('FISCAL_WSFEV1_TESTING_WSDL', 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL'),
            'production' => env('FISCAL_WSFEV1_PRODUCTION_WSDL', 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL'),
        ],
    ],

    'defaults' => [
        'concept' => (int) env('FISCAL_DEFAULT_CONCEPT', 1),
        'currency' => env('FISCAL_DEFAULT_CURRENCY', 'PES'),
        'currency_rate' => (float) env('FISCAL_DEFAULT_CURRENCY_RATE', 1),
        'consumer_final_doc_type' => (int) env('FISCAL_CONSUMER_FINAL_DOC_TYPE', 99),
        'consumer_final_doc_number' => (int) env('FISCAL_CONSUMER_FINAL_DOC_NUMBER', 0),
        'consumer_final_tax_condition_id' => (int) env('FISCAL_CONSUMER_FINAL_TAX_CONDITION_ID', 5),
        'iva_id' => (int) env('FISCAL_DEFAULT_IVA_ID', 5),
    ],

    'logging' => [
        'max_payload_chars' => (int) env('FISCAL_LOG_MAX_PAYLOAD_CHARS', 8000),
    ],
];
