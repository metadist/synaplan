<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * The recommended default-model bindings of a provider and its place in the
 * first-run preference order.
 */
final readonly class ProviderDefaults
{
    /**
     * @param array<string, string> $bindings       capability (CHAT, SORT, …) => `service:providerId:tag` catalog key
     * @param ?int                  $preferenceRank 1 = tried first when no chat provider is ready; null = never auto-picked
     */
    public function __construct(
        public array $bindings,
        public ?int $preferenceRank,
    ) {
    }
}
