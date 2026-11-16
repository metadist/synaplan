<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The web client's calls against one stack: sign in, open a chat, attach a
 * file, send a turn, read a research run, clean up, sign out.
 *
 * The session cookies stay in this object. They are sent to the configured
 * origin only: redirects are not followed, and a password is not sent over
 * plain HTTP to a host that is not this machine. The access cookie lives for
 * five minutes, so a request answered with 401 is repeated once after
 * `POST /api/v1/auth/refresh`, as the web client does.
 */
final class StackClient
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', 'backend', 'host.docker.internal'];
    private const REQUEST_SECONDS = 60.0;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_CONFLICT = 409;

    /** @var array<string, string> cookie name => value */
    private array $cookies = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
    ) {
        $parts = parse_url($baseUrl);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $path = \is_array($parts) ? ($parts['path'] ?? '') : '';
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || !\in_array($path, ['', '/'], true)) {
            throw new \RuntimeException('The base URL must be an http(s) origin without credentials, a path or a query. Nothing was sent.');
        }
        if ('http' === $scheme && !\in_array($host, self::LOCAL_HOSTS, true) && !str_ends_with($host, '.localhost')) {
            throw new \RuntimeException('The password is not sent over plain HTTP to '.$host.'. Use https. Nothing was sent.');
        }
    }

    public function signIn(string $email, string $password): void
    {
        try {
            $response = $this->http->request('POST', $this->url('/api/v1/auth/login'), [
                'json' => ['email' => $email, 'password' => $password],
                'max_redirects' => 0,
                'timeout' => self::REQUEST_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $this->keepCookies($response);
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('Signing in failed: the stack at '.$this->baseUrl.' did not answer ('.$e::class.').', 0, $e);
        }
        if (200 !== $status) {
            $this->cookies = [];
            throw new \RuntimeException('Signing in answered HTTP '.$status.'. Check --email and the password.');
        }
        if ([] === $this->cookies) {
            throw new \RuntimeException('Signing in set no cookie.');
        }
    }

    /**
     * Revokes the session on the server. The cookies are dropped here even
     * when the stack does not answer.
     */
    public function signOut(): void
    {
        if ([] === $this->cookies) {
            return;
        }
        try {
            $this->http->request('POST', $this->url('/api/v1/auth/logout'), $this->options())->getStatusCode();
        } catch (ExceptionInterface) {
            // The refresh token then runs out on its own.
        } finally {
            $this->cookies = [];
        }
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
        $this->json('DELETE', '/api/v1/chats/'.$chatId);
    }

    /**
     * Deleting the chat keeps its uploads in the account's files; this
     * removes one of them and its index entries.
     */
    public function deleteFile(int $fileId): void
    {
        $this->json('DELETE', '/api/v1/files/'.$fileId);
    }

    public function attach(string $path): int
    {
        // The body is a one-time stream, so it is built again for a repeat.
        $body = $this->json('POST', '/api/v1/messages/upload-file', static function () use ($path): array {
            $form = new FormDataPart(['file' => DataPart::fromPath($path)]);

            return ['headers' => $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToIterable()];
        });
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
        $body = [
            'chatId' => (string) $chatId,
            'message' => $turn->text,
            'webSearch' => $turn->webSearch ? '1' : '0',
            'language' => $turn->language,
        ];
        if ([] !== $fileIds) {
            $body['fileIds'] = implode(',', $fileIds);
        }
        [$response, $status] = $this->send('POST', '/api/v1/messages/stream', [
            'json' => $body,
            'headers' => ['Accept' => 'text/event-stream'],
            'timeout' => $timeoutSeconds,
            'max_duration' => $timeoutSeconds,
        ]);
        if (200 !== $status) {
            throw new \RuntimeException('The stream answered HTTP '.$status.'.');
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
     * Replies for the person. False when the run was no longer waiting for
     * that reply (HTTP 409): it moved on between the read and the reply.
     *
     * @param array<string, string> $input
     */
    public function researchInput(string $runId, array $input): bool
    {
        $path = '/api/v1/research/'.rawurlencode($runId).'/input';
        try {
            [$response, $status] = $this->send('POST', $path, ['json' => $input]);
            $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('POST '.$path.' failed: '.$e->getMessage(), 0, $e);
        }
        if (self::HTTP_CONFLICT === $status) {
            return false;
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('POST '.$path.' answered HTTP '.$status.'.');
        }

        return true;
    }

    /**
     * Stops a run the runner no longer waits for, so it stops spending.
     */
    public function cancelResearch(string $runId): void
    {
        $this->json('POST', '/api/v1/research/'.rawurlencode($runId).'/cancel');
    }

    /**
     * @param array<string, mixed>|(\Closure(): array<string, mixed>) $options
     *
     * @return array<mixed>
     */
    private function json(string $method, string $path, array|\Closure $options = []): array
    {
        try {
            [$response, $status] = $this->send($method, $path, $options);
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
     * One request with the session; repeated once after a refresh when the
     * access cookie has run out.
     *
     * @param array<string, mixed>|(\Closure(): array<string, mixed>) $options
     *
     * @return array{ResponseInterface, int}
     */
    private function send(string $method, string $path, array|\Closure $options = []): array
    {
        $build = fn (): array => $this->options($options instanceof \Closure ? $options() : $options);
        $response = $this->http->request($method, $this->url($path), $build());
        $status = $response->getStatusCode();
        if (self::HTTP_UNAUTHORIZED === $status && $this->refresh()) {
            $response = $this->http->request($method, $this->url($path), $build());
            $status = $response->getStatusCode();
        }

        return [$response, $status];
    }

    private function refresh(): bool
    {
        $response = $this->http->request('POST', $this->url('/api/v1/auth/refresh'), $this->options());
        if (200 !== $response->getStatusCode()) {
            return false;
        }
        $this->keepCookies($response);

        return true;
    }

    private function keepCookies(ResponseInterface $response): void
    {
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $header) {
            [$name, $value] = array_pad(explode('=', explode(';', $header, 2)[0], 2), 2, '');
            $name = trim($name);
            if ('' === $name) {
                continue;
            }
            // A cookie the server clears arrives empty or as "deleted".
            if ('' === $value || 'deleted' === $value) {
                unset($this->cookies[$name]);
                continue;
            }
            $this->cookies[$name] = $value;
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function options(array $options = []): array
    {
        if ([] === $this->cookies) {
            throw new \RuntimeException('Not signed in. Nothing was sent.');
        }
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name.'='.$value;
        }
        $headers = \is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $headers['Cookie'] = implode('; ', $pairs);

        return ['timeout' => self::REQUEST_SECONDS, 'max_redirects' => 0, ...$options, 'headers' => $headers];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
