<?php

declare(strict_types=1);

namespace App\Plug;

/**
 * Two adapters registered for the same port claim the same key. Thrown while a
 * registry indexes its adapters, so a plugin that collides with a core key (or
 * another plugin) is a boot error, not a silently shadowed provider. Authors
 * are told to prefix the key with the plugin id when in doubt.
 */
final class DuplicatePlugKeyException extends \RuntimeException
{
    public function __construct(string $port, string $key)
    {
        parent::__construct(sprintf('Duplicate plug key "%s" for port "%s": two adapters may not share a key.', $key, $port));
    }
}
