<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

/**
 * The BCONFIG row (owner 0) that holds a setting, which takes effect without
 * a restart: its group and setting, and whether the value is a credential
 * stored encrypted. Without a group the row lives in QDRANT_SEARCH; without
 * a key, under the field's own name.
 */
final readonly class DatabaseRow
{
    public const OWNER = 0;
    public const DEFAULT_GROUP = 'QDRANT_SEARCH';

    public function __construct(
        public ?string $group = null,
        public ?string $key = null,
        public bool $encrypted = false,
    ) {
    }
}
