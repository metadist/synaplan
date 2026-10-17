<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use OpenAI\Client;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An OpenAI SDK client on a Guzzle mock for the provider request snapshots.
 *
 * It answers with the queued responses in order and records every request
 * the SDK sends: method, URL, headers (the key and the multipart boundary
 * masked, Guzzle's own User-Agent left out) and the body decoded and
 * hashed, a multipart body as its fields and a file as its name, size and
 * hash. A
 * request beyond the queue, or a queued response nobody asked for, fails the
 * test.
 */
final class OpenAISdkRecorder
{
    /** @var array<int, array{request: RequestInterface, response: ?ResponseInterface, error: mixed, options: array<string, mixed>}> */
    private array $history = [];

    private readonly MockHandler $mock;

    /**
     * @param list<ResponseInterface> $responses
     * @param list<string>            $secrets
     */
    public function __construct(array $responses, private readonly array $secrets)
    {
        $this->mock = new MockHandler($responses);
    }

    /**
     * @return \Closure(string): Client
     */
    public function factory(): \Closure
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $guzzle = new GuzzleClient(['handler' => $stack]);

        return static fn (string $key): Client => \OpenAI::factory()->withApiKey($key)->withHttpClient($guzzle)->make();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        foreach ($this->history as $entry) {
            Assert::assertNotInstanceOf(\OutOfBoundsException::class, $entry['error'], 'An SDK request beyond the queued responses.');
        }
        Assert::assertSame(0, $this->mock->count(), 'Queued SDK responses that were never requested.');

        return array_values(array_map(fn (array $entry): array => $this->describe($entry['request']), $this->history));
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if ('user-agent' === strtolower((string) $name)) {
                continue;
            }
            foreach ($values as $value) {
                $headers[] = $this->mask(preg_replace('/boundary=[^;]+/', 'boundary=<boundary>', $name.': '.$value) ?? '');
            }
        }
        sort($headers);

        $body = (string) $request->getBody();
        $type = $request->getHeaderLine('Content-Type');
        $decoded = json_decode($body, true);

        return array_filter([
            'method' => $request->getMethod(),
            'url' => $this->mask((string) $request->getUri()),
            'headers' => $headers,
            'body' => match (true) {
                '' === $body => null,
                str_starts_with($type, 'multipart/form-data') => $this->multipart($body, $type),
                \is_array($decoded) => $decoded,
                default => $this->mask($body),
            },
            'body_sha1' => '' === $body || str_starts_with($type, 'multipart/form-data') ? null : sha1($body),
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function multipart(string $body, string $type): array
    {
        preg_match('/boundary=("?)([^";]+)\1/', $type, $boundary);
        $fields = [];
        foreach (explode('--'.($boundary[2] ?? ''), $body) as $part) {
            if ('' === trim($part) || '--' === trim($part)) {
                continue;
            }
            [$head, $content] = explode("\r\n\r\n", ltrim($part, "\r\n"), 2) + [1 => ''];
            $content = substr($content, 0, -2);
            preg_match('/name="([^"]*)"/', $head, $name);
            preg_match('/filename="([^"]*)"/', $head, $file);
            $fields[$name[1] ?? '?'] = [] === $file
                ? $this->mask($content)
                : ['filename' => $file[1], 'bytes' => \strlen($content), 'sha1' => sha1($content)];
        }

        return $fields;
    }

    private function mask(string $text): string
    {
        return str_replace($this->secrets, ProviderHttpRecorder::SECRET, $text);
    }
}
