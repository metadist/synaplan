<?php

declare(strict_types=1);

namespace App\Service\Egress;

interface EgressCache
{
    public function put(string $key, string $value, int $ttlSeconds): bool;
}
