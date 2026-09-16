<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

/**
 * Metadata-only card for share dialogs and "shared with this group" lists.
 * Never includes resource content.
 *
 * @phpstan-type ResourceCardMeta array<string, scalar|null>
 */
final readonly class ResourceCard
{
    /**
     * @param ResourceCardMeta $meta
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $icon,
        public array $meta = [],
    ) {
    }
}
