<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Log\LoggerInterface;

/**
 * Sends a reply with its generated files as real Telegram uploads, so it
 * works without a public file URL. Files Telegram cannot take stay in
 * Synaplan and are returned as too large or failed. Nothing is sent when
 * there is neither text nor a file to send.
 */
final readonly class TelegramMediaSender
{
    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private TelegramBotApi $api,
        private TelegramVoiceConverter $voice,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * @param list<TelegramOutgoingFile> $files
     * @param array<string, mixed>|null  $keyboard sits on the last message sent
     *
     * @throws TelegramChannelException when the token was revoked or the bot is blocked
     */
    public function deliver(string $token, string $chatId, string $text, array $files, ?array $keyboard = null, ?int $replyTo = null): TelegramDelivery
    {
        $sendable = [];
        $tooLarge = [];
        $failed = [];
        foreach ($files as $file) {
            $absolute = $this->resolve($file);
            if (null === $absolute) {
                $failed[] = $file;
                continue;
            }
            if ((int) filesize($absolute) > TelegramBotApi::MAX_UPLOAD_BYTES) {
                $tooLarge[] = $file;
                continue;
            }
            $sendable[] = [$file, $absolute];
        }

        $text = trim($text);
        $caption = '';
        $ids = [];
        if (1 === count($sendable) && '' !== $text && mb_strlen($text) <= TelegramBotApi::MAX_CAPTION) {
            $caption = $text;
        } elseif ('' !== $text) {
            $ids = $this->api->sendMessage($token, $chatId, $text, [] === $sendable ? $keyboard : null, $replyTo);
            $replyTo = null;
        }

        $sent = 0;
        $last = count($sendable) - 1;
        foreach ($sendable as $index => [$file, $absolute]) {
            $id = $this->sendOne($token, $chatId, $file, $absolute, 0 === $index ? $caption : '', $index === $last ? $keyboard : null, $replyTo);
            if (null === $id) {
                $failed[] = $file;
                continue;
            }
            $ids[] = $id;
            $replyTo = null;
            ++$sent;
        }

        if ([] !== $sendable && 0 === $sent) {
            if ('' !== $caption) {
                $ids = $this->api->sendMessage($token, $chatId, $caption, $keyboard, $replyTo);
            } elseif (null !== $keyboard && [] !== $ids) {
                $this->api->editMessageReplyMarkup($token, $chatId, $ids[count($ids) - 1], $keyboard);
            }
        }

        return new TelegramDelivery($ids, $tooLarge, $failed, 0 === $sent);
    }

    /**
     * @param array<string, mixed>|null $keyboard
     */
    private function sendOne(string $token, string $chatId, TelegramOutgoingFile $file, string $absolute, string $caption, ?array $keyboard, ?int $replyTo): ?int
    {
        $method = $this->methodFor($file, $absolute);
        $path = $absolute;
        $name = $file->displayName();
        $converted = null;
        if (TelegramFileMethod::Voice === $method) {
            $converted = $this->voice->toVoice($absolute);
            if (null === $converted) {
                $method = TelegramFileMethod::Audio;
            } else {
                $path = $converted;
                $name = pathinfo($name, \PATHINFO_FILENAME).'.ogg';
            }
        }

        try {
            try {
                $this->api->sendChatAction($token, $chatId, $method->chatAction());
            } catch (TelegramChannelException) {
                // The upload indicator is cosmetic; a revoked token surfaces on the upload itself.
            }

            return $this->api->sendFile($token, $chatId, $method, $path, $name, $caption, $keyboard, $replyTo);
        } catch (TelegramChannelException $e) {
            if (in_array($e->errorCode, [TelegramChannelException::TOKEN_REVOKED, TelegramChannelException::BOT_BLOCKED], true)) {
                throw $e;
            }
            $this->logger->warning('Telegram file upload failed', ['type' => $file->type, 'error' => $e->errorCode]);

            return null;
        } finally {
            if (null !== $converted) {
                @unlink($converted);
            }
        }
    }

    private function methodFor(TelegramOutgoingFile $file, string $absolute): TelegramFileMethod
    {
        $extension = strtolower(pathinfo($absolute, \PATHINFO_EXTENSION));

        return match ($file->type) {
            TelegramOutgoingFile::IMAGE => in_array($extension, self::PHOTO_EXTENSIONS, true) && (int) filesize($absolute) <= TelegramBotApi::MAX_PHOTO_BYTES
                ? TelegramFileMethod::Photo
                : TelegramFileMethod::Document,
            TelegramOutgoingFile::VIDEO => TelegramFileMethod::Video,
            TelegramOutgoingFile::AUDIO => TelegramFileMethod::Voice,
            default => TelegramFileMethod::Document,
        };
    }

    private function resolve(TelegramOutgoingFile $file): ?string
    {
        $base = realpath($this->uploadDir);
        $candidate = realpath($this->uploadDir.'/'.$file->relativePath());
        if (false === $base || false === $candidate || !is_file($candidate)) {
            $this->logger->warning('Telegram reply file is missing', ['type' => $file->type]);

            return null;
        }
        if (!str_starts_with($candidate, $base.\DIRECTORY_SEPARATOR)) {
            $this->logger->warning('Telegram reply file is outside the upload directory', ['type' => $file->type]);

            return null;
        }

        return $candidate;
    }
}
