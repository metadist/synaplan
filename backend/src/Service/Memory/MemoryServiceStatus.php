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
     * @return array{available: bool, configured: bool, error?: string}
     */
    public function probe(): array
    {
        $configured = '' !== trim($this->qdrantUrl);
        if (!$configured) {
            return ['available' => false, 'configured' => false];
        }

        if ($this->memoryService->isReachableNow()) {
            return ['available' => true, 'configured' => true];
        }

        $result = ['available' => false, 'configured' => true];
        $reason = $this->memoryService->getQdrantClient()->unavailableReason();
        if (is_string($reason) && '' !== $reason) {
            $result['error'] = $reason;
        }

        return $result;
    }
}
