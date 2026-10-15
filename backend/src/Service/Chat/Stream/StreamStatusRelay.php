<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\AI\Service\ProviderDisplayNames;
use App\Service\Realtime\SseWriter;

/**
 * Forwards the pipeline's progress statuses as SSE events of the same name.
 *
 * The status name passes through unchanged — that is part of the event
 * contract. `complete` is the turn's own terminal event and is never
 * forwarded from the pipeline.
 */
final readonly class StreamStatusRelay
{
    public function __construct(
        private SseWriter $sse,
        private ?ProviderDisplayNames $providerDisplayNames = null,
    ) {
    }

    /**
     * @param array{status: string, message: string, metadata?: mixed, timestamp: mixed} $statusUpdate
     */
    public function __invoke(array $statusUpdate): void
    {
        if ('complete' === $statusUpdate['status']) {
            return;
        }

        $metadata = is_array($statusUpdate['metadata'] ?? null) ? $statusUpdate['metadata'] : [];
        // "claude-opus-4-8 by Anthropic": the narration names the vendor,
        // routing only knows the service key.
        if (null !== $this->providerDisplayNames) {
            $metadata = $this->providerDisplayNames->enrich($metadata);
        }

        $this->sse->send((string) $statusUpdate['status'], [
            'message' => $statusUpdate['message'],
            'metadata' => $metadata,
            'timestamp' => $statusUpdate['timestamp'],
        ]);
    }
}
