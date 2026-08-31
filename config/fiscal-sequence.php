<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fiscal sequence serialization
    |--------------------------------------------------------------------------
    |
    | The store must be shared by every apiArca instance. In production use a
    | cache backend with atomic distributed locks (Redis or the shared database),
    | never a per-instance file/array store.
    |
    */
    'store' => env('FISCAL_SEQUENCE_LOCK_STORE', env('CACHE_STORE')),
    'ttl_seconds' => (int) env('FISCAL_SEQUENCE_LOCK_TTL_SECONDS', 240),
    'wait_seconds' => (int) env('FISCAL_SEQUENCE_LOCK_WAIT_SECONDS', 15),
];
