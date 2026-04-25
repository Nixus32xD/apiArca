<?php

namespace App\Services\Fiscal\Data;

use Carbon\CarbonInterface;

class AccessTicketData
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $token,
        public readonly string $sign,
        public readonly CarbonInterface $generationTime,
        public readonly CarbonInterface $expirationTime,
        public readonly array $rawResponse = [],
    ) {}
}
