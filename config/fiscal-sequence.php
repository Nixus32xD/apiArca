<?php

return [
    // Must be a distributed atomic lock backend in production (Redis, database,
    // memcached or DynamoDB). Local file/array/null stores fail closed.
    'store' => env('FISCAL_SEQUENCE_LOCK_STORE', env('CACHE_STORE')),
    'ttl_seconds' => (int) env('FISCAL_SEQUENCE_LOCK_TTL_SECONDS', 240),
    'wait_seconds' => (int) env('FISCAL_SEQUENCE_LOCK_WAIT_SECONDS', 15),
];
