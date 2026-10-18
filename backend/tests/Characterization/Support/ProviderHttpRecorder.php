<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP client double for the provider request snapshots.
 *
 * It answers with the queued responses in order and records every request
 * it is sent: method, URL, headers, body (decoded for reading, hashed so a
 * byte that changes is caught) and time limits, with the given secrets
 * masked. A request beyond the queue is answered with an error status and
 * fails the test, and so does a queued response nobody asked for, so a
 * request can neither appear nor vanish unnoticed.
 */
final class ProviderHttpRecorder
{
    public const SECRET = '<secret>';

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    /** @var list<string> */
    private array $unexpected = [];

    /**
     * @param list<ResponseInterface> $responses
     * @param list<string>            $secrets
     */
    public function __construct(private array $responses, private readonly array $secrets)
    {
    }

    public function client(): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requests[] = $this->describe($method, $url, $options);

            if ([] === $this->responses) {
                $this->unexpected[] = $method.' '.$this->mask($url);

                return new MockResponse('unexpected request', ['http_code' => 599]);
            }

            return array_shift($this->responses);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        Assert::assertSame([], $this->unexpected, 'Requests beyond the queued responses.');
        Assert::assertSame(0, \count($this->responses), 'Queued responses that were never requested.');

        return $this->requests;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function describe(string $method, string $url, array $options): array
    {
        $headers = [];
        foreach ($options['normalized_headers'] ?? [] as $lines) {
            foreach ($lines as $line) {
                $headers[] = $this->mask($line);
            }
        }
        sort($headers);

        $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
        $decoded = '' === $body ? null : json_decode($body, true);

        return array_filter([
            'method' => $method,
            'url' => $this->mask($url),
            'headers' => $headers,
            'body' => \is_array($decoded) ? $this->maskAll($decoded) : ('' === $body ? null : $this->mask($body)),
            'body_sha1' => '' === $body ? null : sha1($body),
            'timeout' => $options['timeout'] ?? null,
            'max_duration' => ($options['max_duration'] ?? 0) > 0 ? $options['max_duration'] : null,
        ], static fn (mixed $value): bool => null !== $value);
    }

    private function mask(string $text): string
    {
        return str_replace($this->secrets, self::SECRET, $text);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function maskAll(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (\is_string($value)) {
                $value = $this->mask($value);
            }
        });

        return $data;
    }
}
