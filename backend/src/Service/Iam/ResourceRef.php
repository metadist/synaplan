<?php

declare(strict_types=1);

namespace App\Service\Iam;

/**
 * Subject for {@see \App\Security\Voter\IamVoter}: a shareable resource by
 * kind key and kind-defined id (e.g. conversation BID, or "{ownerId}:{groupKey}").
 */
final readonly class ResourceRef
{
    public function __construct(
        public string $kind,
        public string $id,
    ) {
    }
}
