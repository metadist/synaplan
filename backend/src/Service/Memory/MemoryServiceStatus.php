<?php

declare(strict_types=1);

namespace App\Service\Memory;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether the vector memory store is configured and answers now — the cheap
 * probe the frontend runs once after load to decide whether Memories show.
 */
final readonly class MemoryServiceStatus
{
    public function __construct(
        private UserMemoryService $memoryService,
        #[Autowire('%env(string:default::QDRANT_URL)%')]
        private string $qdrantUrl,
    ) {
    }

    /**
     * @return array{available: bool, configured: bool}
     */
    public function probe(): array
    {
        $configured = '' !== trim($this->qdrantUrl);

        return [
            'available' => $configured && $this->memoryService->isReachableNow(),
            'configured' => $configured,
        ];
    }
}
