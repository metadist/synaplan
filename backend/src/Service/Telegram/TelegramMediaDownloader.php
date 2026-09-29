<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\FileStorageService;
use App\Service\Message\MessagePreProcessor;
use App\Service\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Downloads a file the person sent to the bot and attaches it to their
 * message, like a chat upload. The type is read from the bytes, not from
 * what Telegram declares.
 */
final readonly class TelegramMediaDownloader
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'audio/ogg' => 'ogg',
        'audio/opus' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/webm' => 'webm',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/x-matroska' => 'mkv',
        'video/x-msvideo' => 'avi',
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'text/markdown' => 'md',
        'text/rtf' => 'rtf',
        'application/rtf' => 'rtf',
        'text/calendar' => 'ics',
        'text/plain' => 'txt',
    ];

    private const BLOCKED_MIMES = [
        'application/x-executable',
        'application/x-dosexec',
        'application/x-mach-binary',
        'application/x-sharedlib',
        'application/x-pie-executable',
        'application/x-msdownload',
        'text/x-shellscript',
        'application/x-sh',
    ];

    private const DEFAULT_EXTENSIONS = [
        TelegramMediaRef::PHOTO => 'jpg',
        TelegramMediaRef::VOICE => 'ogg',
        TelegramMediaRef::VIDEO_NOTE => 'mp4',
        TelegramMediaRef::VIDEO => 'mp4',
        TelegramMediaRef::ANIMATION => 'mp4',
        TelegramMediaRef::STICKER => 'webp',
    ];

    public function __construct(
        private TelegramBotApi $api,
        private FileStorageService $storage,
        private StorageQuotaService $quota,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws TelegramMediaRejected
     */
    public function attach(string $token, User $owner, TelegramMediaRef $ref, Message $message): File
    {
        $userId = (int) $owner->getId();
        if (null !== $ref->fileSize && $ref->fileSize > TelegramBotApi::MAX_DOWNLOAD_BYTES) {
            throw new TelegramMediaRejected(TelegramMediaRejected::TOO_LARGE);
        }
        $remaining = $this->quota->getRemainingStorage($owner);
        if (null !== $ref->fileSize && $ref->fileSize > $remaining) {
            throw new TelegramMediaRejected(TelegramMediaRejected::STORAGE_FULL);
        }

        try {
            $remote = $this->api->getFile($token, $ref->fileId);
            $content = $this->api->downloadFile($token, $remote['path']);
        } catch (TelegramChannelException $e) {
            throw new TelegramMediaRejected(TelegramChannelException::FILE_TOO_LARGE === $e->errorCode ? TelegramMediaRejected::TOO_LARGE : TelegramMediaRejected::DOWNLOAD_FAILED);
        }

        $bytes = strlen($content);
        if ($bytes > $remaining) {
            throw new TelegramMediaRejected(TelegramMediaRejected::STORAGE_FULL);
        }

        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';
        $extension = $this->extensionFor($ref, $mime);
        if (null === $extension) {
            $this->logger->info('Telegram file skipped (type not allowed)', ['kind' => $ref->kind, 'mime' => $mime]);
            throw new TelegramMediaRejected(TelegramMediaRejected::UNSUPPORTED);
        }

        $displayName = $this->displayName($ref, $extension);
        $stored = $this->storage->storeRawContent($content, $userId, $displayName, $mime);
        if (!$stored['success'] || '' === $stored['path']) {
            throw new TelegramMediaRejected(TelegramMediaRejected::DOWNLOAD_FAILED);
        }

        $file = new File();
        $file->setUserId($userId);
        $file->setFilePath($stored['path']);
        $file->setFileType($extension);
        $file->setFileName($displayName);
        $file->setOriginalName($displayName);
        $file->setFileSize($stored['size']);
        $file->setFileMime($mime);
        $file->setStatus('uploaded');
        $file->setSource('telegram');
        $messageId = $message->getId();
        if (null !== $messageId) {
            $file->setMessageId($messageId);
        }
        $this->em->persist($file);
        $message->addFile($file);
        $message->setFile(1);
        $this->em->flush();

        return $file;
    }

    /**
     * The file name wins when it names an allowed type that matches the
     * bytes; otherwise the sniffed type decides.
     */
    private function extensionFor(TelegramMediaRef $ref, string $mime): ?string
    {
        if (in_array($mime, self::BLOCKED_MIMES, true)) {
            return null;
        }
        $allowed = FileStorageService::getAllowedExtensions();
        $named = null !== $ref->fileName ? strtolower(pathinfo($ref->fileName, \PATHINFO_EXTENSION)) : '';
        if ('' !== $named && in_array($named, $allowed, true) && $this->family($named) === $this->mimeFamily($mime)) {
            return $named;
        }
        $sniffed = self::MIME_EXTENSIONS[$mime] ?? null;
        if (null !== $sniffed && in_array($sniffed, $allowed, true)) {
            return $sniffed;
        }
        $default = self::DEFAULT_EXTENSIONS[$ref->kind] ?? null;
        if (null !== $default && $this->family($default) === $this->mimeFamily($mime)) {
            return $default;
        }

        return null;
    }

    private function family(string $extension): string
    {
        if (in_array($extension, [...MessagePreProcessor::IMAGE_EXTENSIONS, 'heic', 'heif'], true)) {
            return 'image';
        }
        if (in_array($extension, [...MessagePreProcessor::AUDIO_EXTENSIONS, ...MessagePreProcessor::VIDEO_EXTENSIONS], true)) {
            return 'media';
        }

        return 'document';
    }

    private function mimeFamily(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/')) {
            return 'media';
        }

        return 'document';
    }

    private function displayName(TelegramMediaRef $ref, string $extension): string
    {
        if (null !== $ref->fileName) {
            $base = pathinfo(basename(str_replace('\\', '/', $ref->fileName)), \PATHINFO_FILENAME);
            if ('' !== trim($base)) {
                return $base.'.'.$extension;
            }
        }

        return 'telegram_'.$ref->kind.'_'.date('Ymd_His').'.'.$extension;
    }
}
