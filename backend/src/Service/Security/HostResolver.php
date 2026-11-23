<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Looks up the addresses a host name answers with. {@see SsrfGuard} asks it
 * before a request is sent.
 */
interface HostResolver
{
    /**
     * Every A and AAAA answer for the name. Empty when it has none.
     *
     * @return list<string>
     */
    public function addresses(string $host): array;
}
