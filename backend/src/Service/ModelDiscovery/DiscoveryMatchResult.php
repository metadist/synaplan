<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * Outcome of matching an upstream listing against the catalog and ignore list.
 */
final readonly class DiscoveryMatchResult
{
    /**
     * @param list<UpstreamModel>                                                                                         $new
     * @param list<array{model: UpstreamModel, reason: string, decidedOn: string}>                                        $ignored
     * @param list<array{openRouterId: string, reason: string, decidedOn: string, why: 'gone_upstream'|'now_in_catalog'}> $obsoleteIgnores
     */
    public function __construct(
        public array $new,
        public array $ignored,
        public array $obsoleteIgnores,
    ) {
    }
}
