<?php

declare(strict_types=1);

namespace App\AI\Catalog\Attribute;

/**
 * Marks a class as an AI provider the catalog collects (`03_contracts.md`
 * §2.3.3). The class implements {@see \App\AI\Catalog\DescribesProviderInterface};
 * the attribute carries nothing, because everything is in the descriptor.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsAiProvider
{
    /** The marker tag the configurator adds; the compiler pass collects it. */
    public const TAG = 'app.ai.provider';
}
