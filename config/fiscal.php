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
        'retry_times' => (int) env('FISCAL_SOAP_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('FISCAL_SOAP_RETRY_SLEEP_MS', 1000),
        'operations' => [
            'wsaa_login' => [
                'timeout' => (int) env('FISCAL_SOAP_WSAA_TIMEOUT', 25),
                'connect_timeout' => (int) env('FISCAL_SOAP_WSAA_CONNECT_TIMEOUT', 10),
                'retry_times' => (int) env('FISCAL_SOAP_WSAA_RETRY_TIMES', 2),
                'retry_sleep_ms' => (int) env('FISCAL_SOAP_WSAA_RETRY_SLEEP_MS', 1000),
            ],
            'wsfe_authorize' => [
                'timeout' => (int) env('FISCAL_SOAP_WSFE_AUTHORIZE_TIMEOUT', 35),
                'connect_timeout' => (int) env('FISCAL_SOAP_WSFE_AUTHORIZE_CONNECT_TIMEOUT', 12),
                'retry_times' => (int) env('FISCAL_SOAP_WSFE_AUTHORIZE_RETRY_TIMES', 2),
                'retry_sleep_ms' => (int) env('FISCAL_SOAP_WSFE_AUTHORIZE_RETRY_SLEEP_MS', 1200),
            ],
            'wsfe_last_authorized' => [
                'timeout' => (int) env('FISCAL_SOAP_WSFE_LAST_AUTH_TIMEOUT', 20),
                'connect_timeout' => (int) env('FISCAL_SOAP_WSFE_LAST_AUTH_CONNECT_TIMEOUT', 10),
                'retry_times' => (int) env('FISCAL_SOAP_WSFE_LAST_AUTH_RETRY_TIMES', 2),
                'retry_sleep_ms' => (int) env('FISCAL_SOAP_WSFE_LAST_AUTH_RETRY_SLEEP_MS', 800),
            ],
            'wsfe_consult' => [
                'timeout' => (int) env('FISCAL_SOAP_WSFE_CONSULT_TIMEOUT', 20),
                'connect_timeout' => (int) env('FISCAL_SOAP_WSFE_CONSULT_CONNECT_TIMEOUT', 10),
                'retry_times' => (int) env('FISCAL_SOAP_WSFE_CONSULT_RETRY_TIMES', 2),
                'retry_sleep_ms' => (int) env('FISCAL_SOAP_WSFE_CONSULT_RETRY_SLEEP_MS', 800),
            ],
            'wsfe_catalog' => [
                'timeout' => (int) env('FISCAL_SOAP_WSFE_CATALOG_TIMEOUT', 15),
                'connect_timeout' => (int) env('FISCAL_SOAP_WSFE_CATALOG_CONNECT_TIMEOUT', 8),
                'retry_times' => (int) env('FISCAL_SOAP_WSFE_CATALOG_RETRY_TIMES', 1),
                'retry_sleep_ms' => (int) env('FISCAL_SOAP_WSFE_CATALOG_RETRY_SLEEP_MS', 500),
            ],
            'wsfe_dummy' => [
                'timeout' => (int) env('FISCAL_SOAP_WSFE_DUMMY_TIMEOUT', 10),
                'connect_timeout' => (int) env('FISCAL_SOAP_WSFE_DUMMY_CONNECT_TIMEOUT', 5),
                'retry_times' => (int) env('FISCAL_SOAP_WSFE_DUMMY_RETRY_TIMES', 1),
                'retry_sleep_ms' => (int) env('FISCAL_SOAP_WSFE_DUMMY_RETRY_SLEEP_MS', 500),
            ],
        ],
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

    'environment' => [
        'strict_endpoint_check' => (bool) env('FISCAL_STRICT_ENDPOINT_ENV_CHECK', true),
    ],
];
