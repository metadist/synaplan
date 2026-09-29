<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Telegram Bot API. Failures become {@see TelegramChannelException} codes.
 * The token is part of every request and file URL and is never logged.
 */
final readonly class TelegramBotApi
{
    public const MAX_CAPTION = 1024;
    /** Bots may download files up to this size from the Bot API. */
    public const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;
    /** Bots may upload files up to this size. */
    public const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;
    /** Larger photos must be sent as a document. */
    public const MAX_PHOTO_BYTES = 10 * 1024 * 1024;

    public const UPDATES = ['message', 'edited_message', 'callback_query'];

    private const MAX_TEXT = 4096;
    private const CONTEXT_CONNECT = 'connect';
    private const CONTEXT_SEND = 'send';
    private const BAD_REQUEST = 400;
    private const TIMEOUT_SECONDS = 15;
    private const UPLOAD_TIMEOUT_SECONDS = 120;
    private const DOWNLOAD_TIMEOUT_SECONDS = 60;

    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        private string $baseUrl = 'https://api.telegram.org',
        private TelegramMessageFormatter $formatter = new TelegramMessageFormatter(),
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
            'allowed_updates' => self::UPDATES,
        ], self::CONTEXT_CONNECT);
    }

    public function deleteWebhook(string $token): void
    {
        $this->call($token, 'deleteWebhook', [
            'drop_pending_updates' => true,
        ], self::CONTEXT_SEND);
    }

    /**
     * @param list<array{command: string, description: string}> $commands
     */
    public function setMyCommands(string $token, array $commands, ?string $languageCode = null): void
    {
        $payload = ['commands' => $commands];
        if (null !== $languageCode) {
            $payload['language_code'] = $languageCode;
        }
        $this->call($token, 'setMyCommands', $payload, self::CONTEXT_SEND);
    }

    public function sendChatAction(string $token, string $chatId, string $action = 'typing'): void
    {
        $this->call($token, 'sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ], self::CONTEXT_SEND);
    }

    /**
     * Sends Markdown as Telegram HTML. When Telegram cannot parse the markup
     * the same chunk goes out as plain text, so the reply is never lost.
     * The keyboard sits on the last chunk, the reply reference on the first.
     *
     * @param array<string, mixed>|null $replyMarkup
     *
     * @return list<int> Telegram message ids, one per chunk
     */
    public function sendMessage(string $token, string $chatId, string $text, ?array $replyMarkup = null, ?int $replyTo = null): array
    {
        $chunks = $this->chunks($text);
        $last = count($chunks) - 1;
        $ids = [];
        foreach ($chunks as $index => $chunk) {
            $payload = ['chat_id' => $chatId];
            if (0 === $index && null !== $replyTo) {
                $payload['reply_parameters'] = ['message_id' => $replyTo, 'allow_sending_without_reply' => true];
            }
            if ($index === $last && null !== $replyMarkup) {
                $payload['reply_markup'] = $replyMarkup;
            }
            $result = $this->callFormatted($token, 'sendMessage', $payload, 'text', $chunk);
            $id = $result['message_id'] ?? null;
            if (is_int($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Replaces the text of a message the bot sent. Returns false when
     * Telegram refuses (too old, deleted, unchanged), so the caller can send
     * a new message instead.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    public function editMessageText(string $token, string $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        $chunks = $this->chunks($text);
        if (1 !== count($chunks)) {
            return false;
        }
        $payload = ['chat_id' => $chatId, 'message_id' => $messageId];
        if (null !== $replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }
        try {
            $this->callFormatted($token, 'editMessageText', $payload, 'text', $chunks[0]);
        } catch (TelegramChannelException $e) {
            if (in_array($e->errorCode, [TelegramChannelException::TOKEN_REVOKED, TelegramChannelException::BOT_BLOCKED], true)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * Best effort: a keyboard that cannot be changed only stays visible.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    public function editMessageReplyMarkup(string $token, string $chatId, int $messageId, ?array $replyMarkup): void
    {
        [$status, $body] = $this->request($token, 'editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup ?? ['inline_keyboard' => []],
        ], self::CONTEXT_SEND);
        if (true !== ($body['ok'] ?? false)) {
            $this->logger->info('Telegram keyboard update skipped', ['status' => $status]);
        }
    }

    /**
     * Stops the loading spinner on a tapped button. A stale callback (older
     * than Telegram keeps it) is ignored.
     */
    public function answerCallbackQuery(string $token, string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if (null !== $text && '' !== $text) {
            $payload['text'] = mb_substr($text, 0, 200);
        }
        [$status, $body] = $this->request($token, 'answerCallbackQuery', $payload, self::CONTEXT_SEND);
        if (true !== ($body['ok'] ?? false)) {
            $this->logger->info('Telegram callback answer skipped', ['status' => $status]);
        }
    }

    /**
     * @return array{path: string, size: int|null}
     */
    public function getFile(string $token, string $fileId): array
    {
        $result = $this->call($token, 'getFile', ['file_id' => $fileId], self::CONTEXT_SEND);
        $path = $result['file_path'] ?? null;
        if (!is_string($path) || '' === $path) {
            // Telegram omits the path for files above its download limit.
            throw new TelegramChannelException(TelegramChannelException::FILE_TOO_LARGE);
        }
        $size = $result['file_size'] ?? null;

        return ['path' => $path, 'size' => is_int($size) ? $size : null];
    }

    /**
     * Downloads a file the bot received, never more than $maxBytes.
     */
    public function downloadFile(string $token, string $filePath, int $maxBytes = self::MAX_DOWNLOAD_BYTES): string
    {
        $url = rtrim($this->baseUrl, '/').'/file/bot'.rawurlencode($token).'/'.ltrim($filePath, '/');
        try {
            $response = $this->http->request('GET', $url, ['timeout' => self::DOWNLOAD_TIMEOUT_SECONDS]);
            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('Telegram file download rejected', ['status' => $response->getStatusCode()]);
                throw new TelegramChannelException(TelegramChannelException::DOWNLOAD_FAILED);
            }
            $content = '';
            foreach ($this->http->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (strlen($content) > $maxBytes) {
                    $response->cancel();
                    throw new TelegramChannelException(TelegramChannelException::FILE_TOO_LARGE);
                }
            }
        } catch (ExceptionInterface $e) {
            // The message can quote the file URL, which carries the token.
            $this->logger->warning('Telegram file download failed', ['exception_class' => $e::class]);
            throw new TelegramChannelException(TelegramChannelException::DOWNLOAD_FAILED);
        }

        return $content;
    }

    /**
     * Uploads one local file with sendPhoto, sendVideo, sendAudio, sendVoice
     * or sendDocument. The caption is Markdown and falls back to plain text.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    public function sendFile(
        string $token,
        string $chatId,
        TelegramFileMethod $method,
        string $absolutePath,
        string $filename,
        string $caption = '',
        ?array $replyMarkup = null,
        ?int $replyTo = null,
    ): ?int {
        $fields = ['chat_id' => $chatId];
        if (null !== $replyMarkup) {
            $fields['reply_markup'] = (string) json_encode($replyMarkup);
        }
        if (null !== $replyTo) {
            $fields['reply_parameters'] = (string) json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true]);
        }
        $caption = mb_substr(trim($caption), 0, self::MAX_CAPTION);

        [$status, $body] = $this->upload($token, $method, $fields + $this->captionFields($caption, true), $absolutePath, $filename);
        if (true !== ($body['ok'] ?? false) && '' !== $caption && self::BAD_REQUEST === (int) ($body['error_code'] ?? $status)) {
            [$status, $body] = $this->upload($token, $method, $fields + $this->captionFields($caption, false), $absolutePath, $filename);
        }
        if (true !== ($body['ok'] ?? false)) {
            $this->fail($method->value, $status, $body, self::CONTEXT_SEND);
        }
        $result = $body['result'] ?? [];
        $id = is_array($result) ? ($result['message_id'] ?? null) : null;

        return is_int($id) ? $id : null;
    }

    /**
     * @return array<string, string>
     */
    private function captionFields(string $caption, bool $html): array
    {
        if ('' === $caption) {
            return [];
        }
        if (!$html) {
            return ['caption' => $caption];
        }

        return ['caption' => $this->formatter->toHtml($caption), 'parse_mode' => 'HTML'];
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function upload(string $token, TelegramFileMethod $method, array $fields, string $absolutePath, string $filename): array
    {
        $form = new FormDataPart($fields + [$method->field() => DataPart::fromPath($absolutePath, $filename)]);
        try {
            $response = $this->http->request('POST', $this->endpoint($token, $method->value), [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => self::UPLOAD_TIMEOUT_SECONDS,
            ]);

            return [$response->getStatusCode(), $response->toArray(false)];
        } catch (HttpExceptionInterface $e) {
            return [$e->getResponse()->getStatusCode(), $this->decode($e->getResponse())];
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Telegram API upload failed', [
                'method' => $method->value,
                'exception_class' => $e::class,
            ]);
            throw new TelegramChannelException(TelegramChannelException::SEND_FAILED);
        }
    }

    /**
     * Sends $text in $field as HTML and retries once as plain text when
     * Telegram rejects the markup.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function callFormatted(string $token, string $method, array $payload, string $field, string $text): array
    {
        [$status, $body] = $this->request($token, $method, $payload + [
            $field => $this->formatter->toHtml($text),
            'parse_mode' => 'HTML',
        ], self::CONTEXT_SEND);
        if (true === ($body['ok'] ?? false)) {
            $result = $body['result'] ?? [];

            return is_array($result) ? $result : [];
        }
        if (self::BAD_REQUEST !== (int) ($body['error_code'] ?? $status)) {
            $this->fail($method, $status, $body, self::CONTEXT_SEND);
        }

        return $this->call($token, $method, $payload + [$field => $text], self::CONTEXT_SEND);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function call(string $token, string $method, array $payload, string $context): array
    {
        [$status, $body] = $this->request($token, $method, $payload, $context);
        if (true !== ($body['ok'] ?? false)) {
            $this->fail($method, $status, $body, $context);
        }

        $result = $body['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(string $token, string $method, array $payload, string $context): array
    {
        try {
            $response = $this->http->request('POST', $this->endpoint($token, $method), [
                'json' => $payload,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $body = $this->decode($response);
        } catch (ExceptionInterface $e) {
            // The message can quote the request URL, which carries the token.
            $this->logger->warning('Telegram API transport failed', [
                'method' => $method,
                'exception_class' => $e::class,
            ]);
            throw new TelegramChannelException(self::CONTEXT_CONNECT === $context ? TelegramChannelException::WEBHOOK_FAILED : TelegramChannelException::SEND_FAILED);
        }

        return [$status, $body];
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
        if (413 === $code) {
            throw new TelegramChannelException(TelegramChannelException::FILE_TOO_LARGE);
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
