<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * What the key-validation endpoint says about the models a provider serves.
 */
enum ModelListing: string
{
    /** The endpoint enumerates models; model health may read it as a hint. */
    case Lists = 'lists';

    /** The endpoint proves the key and nothing else (a whoami); health probes it but learns no model. */
    case CredentialsOnly = 'credentials_only';

    /** No validation endpoint, or one the health probe must not use. */
    case None = 'none';
}
