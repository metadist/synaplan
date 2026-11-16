<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The web client's calls against one stack: sign in, open a chat, attach a
 * file, send a turn, read a research run, clean up.
 *
 * The session cookie stays in this object. It is sent to the configured
 * origin only: redirects are not followed, and a password is not sent over
 * plain HTTP to a host that is not this machine.
 */
final class StackClient
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', 'backend', 'host.docker.internal'];
    private const REQUEST_SECONDS = 60.0;

    private string $cookie = '';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
    ) {
        $parts = parse_url($baseUrl);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host || isset($parts['user']) || isset($parts['query'])) {
            throw new \RuntimeException('The base URL must be an http(s) origin without credentials or a query. Nothing was sent.');
        }
        if ('http' === $scheme && !\in_array($host, self::LOCAL_HOSTS, true) && !str_ends_with($host, '.localhost')) {
            throw new \RuntimeException('The password is not sent over plain HTTP to '.$host.'. Use https. Nothing was sent.');
        }
    }

    public function signIn(string $email, string $password): void
    {
        $response = $this->http->request('POST', $this->url('/api/v1/auth/login'), [
            'json' => ['email' => $email, 'password' => $password],
            'max_redirects' => 0,
            'timeout' => self::REQUEST_SECONDS,
        ]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Signing in answered HTTP '.$response->getStatusCode().'.');
        }
        $cookies = [];
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $header) {
            $cookies[] = explode(';', $header, 2)[0];
        }
        if ([] === $cookies) {
            throw new \RuntimeException('Signing in set no cookie.');
        }
        $this->cookie = implode('; ', $cookies);
    }

    /**
     * The model ids the account's turns resolve to, by capability.
     *
     * @return array<string, int>
     */
    public function defaultModels(): array
    {
        $defaults = $this->json('GET', '/api/v1/config/models/defaults')['defaults'] ?? [];
        $out = [];
        foreach (\is_array($defaults) ? $defaults : [] as $capability => $modelId) {
            if (\is_string($capability) && \is_int($modelId)) {
                $out[$capability] = $modelId;
            }
        }

        return $out;
    }

    public function createChat(string $title): int
    {
        $body = $this->json('POST', '/api/v1/chats', ['json' => ['title' => $title]]);
        $id = \is_array($body['chat'] ?? null) ? ($body['chat']['id'] ?? null) : ($body['id'] ?? null);
        if (!\is_int($id)) {
            throw new \RuntimeException('Creating a chat returned no chat id.');
        }

        return $id;
    }

    public function deleteChat(int $chatId): void
    {
        $this->http->request('DELETE', $this->url('/api/v1/chats/'.$chatId), $this->options())->getStatusCode();
    }

    public function attach(string $path): int
    {
        $form = new FormDataPart(['file' => DataPart::fromPath($path)]);
        $body = $this->json('POST', '/api/v1/messages/upload-file', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
        ]);
        if (!\is_int($body['file_id'] ?? null)) {
            throw new \RuntimeException('Attaching '.basename($path).' returned no file id.');
        }

        return $body['file_id'];
    }

    /**
     * Sends one turn and yields its events as they arrive.
     *
     * @param list<int> $fileIds
     *
     * @return \Generator<int, array<mixed>>
     */
    public function turn(int $chatId, CorpusTurn $turn, array $fileIds, float $timeoutSeconds): \Generator
    {
        $body = ['chatId' => (string) $chatId, 'message' => $turn->text, 'webSearch' => $turn->webSearch ? '1' : '0'];
        if ([] !== $fileIds) {
            $body['fileIds'] = implode(',', $fileIds);
        }
        $response = $this->http->request('POST', $this->url('/api/v1/messages/stream'), $this->options([
            'json' => $body,
            'headers' => ['Accept' => 'text/event-stream'],
            'timeout' => $timeoutSeconds,
            'max_duration' => $timeoutSeconds,
        ]));
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('The stream answered HTTP '.$response->getStatusCode().'.');
        }

        $frames = new SseFrames();
        foreach ($this->http->stream($response) as $chunk) {
            foreach ($frames->feed($chunk->getContent()) as $frame) {
                yield $frame;
            }
        }
    }

    /**
     * @return array<mixed>
     */
    public function research(string $runId): array
    {
        return $this->json('GET', '/api/v1/research/'.rawurlencode($runId));
    }

    /**
     * @param array<string, string> $input
     */
    public function researchInput(string $runId, array $input): void
    {
        $this->json('POST', '/api/v1/research/'.rawurlencode($runId).'/input', ['json' => $input]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function json(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $this->url($path), $this->options($options));
            $status = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException($method.' '.$path.' failed: '.$e->getMessage(), 0, $e);
        }
        if ($status < 200 || $status >= 300 || !\is_array($body)) {
            throw new \RuntimeException($method.' '.$path.' answered HTTP '.$status.'.');
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function options(array $options = []): array
    {
        if ('' === $this->cookie) {
            throw new \RuntimeException('Not signed in. Nothing was sent.');
        }
        $headers = \is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $headers['Cookie'] = $this->cookie;

        return ['timeout' => self::REQUEST_SECONDS, 'max_redirects' => 0, ...$options, 'headers' => $headers];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
