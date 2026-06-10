<?php

declare(strict_types=1);

namespace App\Realtime\Publisher;

use App\Realtime\Channel\ChannelInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes via Centrifugo's HTTP server API.
 *
 * Why HTTP and not the gRPC SDK?
 *   * No new PHP extensions required (works under FrankenPHP unchanged).
 *   * Centrifugo's HTTP API is documented as stable across v5/v6.
 *   * Trivial to mock in tests via {@see HttpClientInterface}.
 *
 * Failure mode: this class never throws. Real-time is a UX enhancement on
 * top of the source-of-truth REST endpoints, so a flaky Centrifugo MUST
 * NOT take down a chat reply or a session takeover. Errors are logged at
 * `warning` level and the request continues.
 *
 * Envelope shape sent on the wire:
 *
 *   { "type": "<eventType>", "ts": <unix-ms>, "data": { ...payload } }
 *
 * Frontend decoders ({@see RealtimeEnvelopeSchema} on the JS side) expect
 * exactly this shape — keep both ends in sync.
 */
final readonly class CentrifugoPublisher implements RealtimePublisherInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $apiUrl,
        private string $apiKey,
        private bool $enabled,
    ) {
    }

    public function publish(ChannelInterface $channel, string $eventType, array $payload): void
    {
        if (!$this->enabled) {
            return;
        }

        if ('' === trim($this->apiUrl) || '' === trim($this->apiKey)) {
            $this->logger->warning('Centrifugo publish skipped: REALTIME_API_URL or REALTIME_API_KEY missing');

            return;
        }

        $envelope = [
            'type' => $eventType,
            'ts' => (int) (microtime(true) * 1000),
            'data' => $payload,
        ];

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->apiKey,
                ],
                'json' => [
                    'method' => 'publish',
                    'params' => [
                        'channel' => $channel->name(),
                        'data' => $envelope,
                    ],
                ],
                'timeout' => 2.0,
                'max_duration' => 3.0,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('Centrifugo publish returned non-2xx', [
                    'status' => $status,
                    'channel' => $channel->name(),
                    'event' => $eventType,
                    'body' => mb_substr((string) $response->getContent(false), 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Centrifugo publish failed', [
                'channel' => $channel->name(),
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
