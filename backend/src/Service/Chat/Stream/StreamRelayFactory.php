<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\AI\Service\ProviderDisplayNames;
use App\Service\Media\MediaCancellationStore;
use App\Service\Realtime\SseWriter;

/**
 * Builds the per-turn relays between the message pipeline and the client.
 */
final readonly class StreamRelayFactory
{
    public function __construct(
        private MediaCancellationStore $cancellationStore,
        private ?ProviderDisplayNames $providerDisplayNames = null,
    ) {
    }

    public function chunks(SseWriter $sse, StreamTurnRequest $turn): StreamChunkRelay
    {
        return new StreamChunkRelay($sse, $this->cancellationStore, (string) $turn->trackId);
    }

    public function statuses(SseWriter $sse): StreamStatusRelay
    {
        return new StreamStatusRelay($sse, $this->providerDisplayNames);
    }
}
