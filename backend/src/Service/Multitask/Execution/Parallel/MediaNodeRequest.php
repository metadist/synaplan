<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Parallel;

/**
 * A self-contained description of a single media-generation node, enough to run
 * it in an isolated subprocess (no entity/context needed there).
 */
final readonly class MediaNodeRequest
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $nodeId,
        public string $capability,
        public string $prompt,
        public ?int $userId,
        public string $language,
        public array $params = [],
    ) {
    }
}
