<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Telegram Bot API. Failures become {@see TelegramChannelException} codes.
 * The token is part of the request URL and is never logged.
 */
final readonly class TelegramBotApi
{
    private const MAX_TEXT = 4096;
    private const CONTEXT_CONNECT = 'connect';
    private const CONTEXT_SEND = 'send';

    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        private string $baseUrl = 'https://api.telegram.org',
    ) {
    }

    public function getMe(string $token): TelegramBotIdentity
    {
        $result = $this->call($token, 'getMe', [], self::CONTEXT_CONNECT);
        $id = $result['id'] ?? null;
        $username = $result['username'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new TelegramChannelException(TelegramChannelException::TOKEN_INVALID);
        }
        if (!is_string($username) || '' === $username) {
            throw new TelegramChannelException(TelegramChannelException::TOKEN_INVALID);
        }

        return new TelegramBotIdentity((int) $id, $username);
    }

    public function setWebhook(string $token, string $url, string $secretToken): void
    {
        $this->call($token, 'setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message'],
        ], self::CONTEXT_CONNECT);
    }

    public function deleteWebhook(string $token): void
    {
        $this->call($token, 'deleteWebhook', [
            'drop_pending_updates' => true,
        ], self::CONTEXT_SEND);
    }

    public function sendChatAction(string $token, string $chatId, string $action = 'typing'): void
    {
        $this->call($token, 'sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ], self::CONTEXT_SEND);
    }

    public function sendMessage(string $token, string $chatId, string $text): void
    {
        foreach ($this->chunks($text) as $chunk) {
            $this->call($token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $chunk,
            ], self::CONTEXT_SEND);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function call(string $token, string $method, array $payload, string $context): array
    {
        try {
            $response = $this->http->request('POST', $this->endpoint($token, $method), [
                'json' => $payload,
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $body = $this->decode($response);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Telegram API transport failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw new TelegramChannelException(self::CONTEXT_CONNECT === $context ? TelegramChannelException::WEBHOOK_FAILED : TelegramChannelException::SEND_FAILED);
        }

        if (true !== ($body['ok'] ?? false)) {
            $this->fail($method, $status, $body, $context);
        }

        $result = $body['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function fail(string $method, int $status, array $body, string $context): never
    {
        $code = (int) ($body['error_code'] ?? $status);
        $this->logger->warning('Telegram API rejected a call', [
            'method' => $method,
            'status' => $status,
            'error_code' => $code,
        ]);

        if (self::CONTEXT_CONNECT === $context && in_array($code, [401, 404], true)) {
            throw new TelegramChannelException(TelegramChannelException::TOKEN_INVALID);
        }
        if (401 === $code) {
            throw new TelegramChannelException(TelegramChannelException::TOKEN_REVOKED);
        }
        if (403 === $code) {
            throw new TelegramChannelException(TelegramChannelException::BOT_BLOCKED);
        }

        throw new TelegramChannelException(self::CONTEXT_CONNECT === $context ? TelegramChannelException::WEBHOOK_FAILED : TelegramChannelException::SEND_FAILED);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            return $response->toArray(false);
        } catch (ExceptionInterface) {
            return [];
        }
    }

    private function endpoint(string $token, string $method): string
    {
        return rtrim($this->baseUrl, '/').'/bot'.rawurlencode($token).'/'.$method;
    }

    /**
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        $text = trim($text);
        if ('' === $text) {
            return ['…'];
        }

        $chunks = [];
        while (mb_strlen($text) > self::MAX_TEXT) {
            $slice = mb_substr($text, 0, self::MAX_TEXT);
            $break = mb_strrpos($slice, "\n");
            if (false === $break || $break < 1000) {
                $break = self::MAX_TEXT;
            }
            $chunks[] = rtrim(mb_substr($text, 0, $break));
            $text = ltrim(mb_substr($text, $break));
        }
        if ('' !== $text) {
            $chunks[] = $text;
        }

        return $chunks;
    }
}
