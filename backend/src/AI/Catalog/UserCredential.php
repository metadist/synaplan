<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * Whether a person may bring their own credential for a provider, and
 * through which path. Separate from where the platform key lives
 * ({@see ProviderKeyStoreKind}): Higgsfield resolves a personal key pair at
 * call time without being on the Messages gateway's BYOK list.
 */
enum UserCredential: string
{
    /** Only the platform credential is ever used. */
    case None = 'none';

    /** The Messages gateway's BYOK list: `UserProviderKeyResolver`, metered at zero cost. */
    case Gateway = 'gateway';

    /** A per-user key pair resolved by the provider's own resolver (`HiggsfieldCredentialResolver`). */
    case Personal = 'personal';
}
