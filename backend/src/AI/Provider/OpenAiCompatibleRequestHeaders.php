<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Headers an OpenAI-compatible endpoint needs on every call that cannot be
 * stored with the endpoint because they change per call (who asks, from
 * which surface). Static headers belong in the endpoint registry instead.
 */
interface OpenAiCompatibleRequestHeaders
{
    /**
     * @param array<string, mixed> $options the provider call options
     *
     * @return array<string, string> empty for endpoints this decorator does not own
     */
    public function forCall(string $endpointName, array $options): array;
}
