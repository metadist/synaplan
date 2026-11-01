<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * Where a provider's platform credential lives. Whether a person may bring
 * their own is {@see UserCredential}.
 */
enum ProviderKeyStoreKind: string
{
    /** One encrypted row in the platform key store (BCONFIG `provider_keys`). */
    case Platform = 'platform';

    /** The OpenAI-compatible endpoint registry: one credential per endpoint. */
    case EndpointRegistry = 'endpoint_registry';

    /** A credential read from the environment only, outside every store. */
    case None = 'none';
}
