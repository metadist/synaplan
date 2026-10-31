<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * A class that declares an AI provider. Implemented by every adapter in
 * `App\AI\Provider\` and by key-only providers that have no adapter yet.
 * The method is static: the catalog reads descriptors without building a
 * single provider.
 */
interface DescribesProviderInterface
{
    public static function descriptor(): ProviderDescriptor;
}
