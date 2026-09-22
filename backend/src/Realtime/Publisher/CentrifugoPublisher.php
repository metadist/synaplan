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
        private string $environment = 'prod',
    ) {
    }

    public function publish(ChannelInterface $channel, string $eventType, array $payload): void
    {
        $this->send('publish', [
            'channel' => $channel->name(),
            'data' => $this->envelope($eventType, $payload),
        ], $channel->name(), $eventType);
    }

    /**
     * @param list<ChannelInterface> $channels
     * @param array<string, mixed>   $payload
     */
    public function publishMany(array $channels, string $eventType, array $payload): void
    {
        if ([] === $channels) {
            return;
        }

        $names = array_map(static fn (ChannelInterface $channel): string => $channel->name(), $channels);
        if (1 === count($names)) {
            $this->send('publish', [
                'channel' => $names[0],
                'data' => $this->envelope($eventType, $payload),
            ], $names[0], $eventType);

            return;
        }

        $this->send('broadcast', [
            'channels' => $names,
            'data' => $this->envelope($eventType, $payload),
        ], implode(',', $names), $eventType);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{type: string, ts: int, data: array<string, mixed>}
     */
    private function envelope(string $eventType, array $payload): array
    {
        return [
            'type' => $eventType,
            'ts' => (int) (microtime(true) * 1000),
            'data' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function send(string $method, array $params, string $channel, string $eventType): void
    {
        if (!$this->enabled) {
            return;
        }

        if ('' === trim($this->apiUrl) || '' === trim($this->apiKey)) {
            $this->logger->warning('Centrifugo publish skipped: REALTIME_API_URL or REALTIME_API_KEY missing');

            return;
        }

        if ('prod' === $this->environment && str_starts_with($this->apiKey, 'changeme')) {
            $this->logger->error('Centrifugo publish skipped: REALTIME_API_KEY still has the "changeme" placeholder value — set a strong random key before enabling realtime in production');

            return;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->apiKey,
                ],
                'json' => [
                    'method' => $method,
                    'params' => $params,
                ],
                'timeout' => 2.0,
                'max_duration' => 3.0,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('Centrifugo publish returned non-2xx', [
                    'status' => $status,
                    'channel' => $channel,
                    'event' => $eventType,
                    'body' => mb_substr((string) $response->getContent(false), 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Centrifugo publish failed', [
                'channel' => $channel,
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
