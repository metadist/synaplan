<?php

declare(strict_types=1);

namespace App\AI\Catalog\Attribute;

/**
 * Marks a class as an AI provider the catalog collects (`03_contracts.md`
 * §2.3.3). The class implements {@see \App\AI\Catalog\DescribesProviderInterface};
 * the descriptor carries everything else.
 *
 * `ProviderCatalogPass` tags the adapter service with one
 * `app.ai.<capability>` tag per capability of the descriptor, keyed by the
 * provider name. The adapter is the class itself unless `$adapter` names
 * another one — the case of a sibling descriptor class kept beside an adapter
 * that is frozen in the size ledger.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsAiProvider
{
    /** The marker tag the configurator adds; the compiler pass collects it. */
    public const TAG = 'app.ai.provider';

    /**
     * @param ?class-string $adapter the service that implements the capabilities; null = the described class itself
     */
    public function __construct(
        public ?string $adapter = null,
    ) {
    }
}
