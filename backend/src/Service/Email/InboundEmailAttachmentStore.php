<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\FileHelper;
use App\Service\File\FileStorageService;
use App\Service\Security\SsrfGuard;
use App\Service\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Persists attachments posted on {@see \App\Controller\WebhookController::email()}
 * as File rows, the same storage step WhatsApp already uses for inbound media.
 *
 * Unsafe URLs, unknown types, oversized payloads and quota misses are skipped;
 * the email itself is still accepted. Automatic vectorization is not started.
 */
final readonly class InboundEmailAttachmentStore
{
    private const FETCH_TIMEOUT_SECONDS = 20;
    private const MAX_REDIRECTS = 5;
    private const MAX_ATTACHMENTS = 10;
    private const FETCH_BUDGET_SECONDS = 30;
    private const MAX_TOTAL_BYTES = FileStorageService::MAX_FILE_SIZE;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
        private FileStorageService $fileStorage,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private StorageQuotaService $storageQuota,
    ) {
    }

    /**
     * @param list<mixed> $attachments
     *
     * @return list<File>
     */
    public function attach(Message $message, User $user, array $attachments): array
    {
        $userId = $user->getId();
        if (null === $userId) {
            return [];
        }

        $stored = [];
        $considered = 0;
        $totalBytes = 0;
        $deadline = microtime(true) + self::FETCH_BUDGET_SECONDS;
        $remainingQuota = $this->storageQuota->getRemainingStorage($user);

        foreach ($attachments as $attachment) {
            if ($considered >= self::MAX_ATTACHMENTS) {
                $this->logger->info('Email webhook: remaining attachments skipped (count cap)');
                break;
            }
            if (!is_array($attachment)) {
                continue;
            }
            ++$considered;

            if (microtime(true) >= $deadline) {
                $this->logger->info('Email webhook: remaining attachments skipped (time budget)');
                break;
            }

            $file = $this->storeOne(
                $message,
                $userId,
                $attachment,
                $deadline,
                $remainingQuota,
                $totalBytes,
            );
            if (null === $file) {
                continue;
            }

            $stored[] = $file;
            $size = $file->getFileSize();
            $remainingQuota = max(0, $remainingQuota - $size);
            $totalBytes += $size;
        }

        if ([] !== $stored) {
            $message->setFile(1);
        }

        return $stored;
    }

    /**
     * @param array<array-key, mixed> $attachment
     */
    private function storeOne(
        Message $message,
        int $userId,
        array $attachment,
        float $deadline,
        int $remainingQuota,
        int $totalBytes,
    ): ?File {
        $url = trim((string) ($attachment['url'] ?? ''));
        if ('' === $url) {
            $this->logger->info('Email webhook: attachment skipped (no url)');

            return null;
        }

        $filename = $this->filenameFrom($attachment, $url);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, FileStorageService::ALLOWED_EXTENSIONS, true)) {
            $this->logger->info('Email webhook: attachment skipped (type not allowed)', [
                'filename' => $filename,
                'extension' => $extension,
            ]);

            return null;
        }

        $declaredSize = $attachment['size'] ?? null;
        $declaredBytes = is_numeric($declaredSize) ? (int) $declaredSize : null;
        if (null !== $declaredBytes && $declaredBytes > FileStorageService::MAX_FILE_SIZE) {
            $this->logger->info('Email webhook: attachment skipped (declared size too large)', [
                'filename' => $filename,
                'size' => $declaredBytes,
            ]);

            return null;
        }
        if (null !== $declaredBytes && ($declaredBytes > $remainingQuota || $totalBytes + $declaredBytes > self::MAX_TOTAL_BYTES)) {
            $this->logger->info('Email webhook: attachment skipped (quota or batch byte budget)', [
                'filename' => $filename,
                'size' => $declaredBytes,
            ]);

            return null;
        }

        $fetched = $this->fetchBytes($url, $deadline);
        if (null === $fetched) {
            return null;
        }

        $bytes = strlen($fetched['content']);
        if ($bytes > $remainingQuota || $totalBytes + $bytes > self::MAX_TOTAL_BYTES) {
            $this->logger->info('Email webhook: attachment skipped (quota or batch byte budget after download)', [
                'filename' => $filename,
                'size' => $bytes,
            ]);

            return null;
        }

        $mime = $this->mimeFrom($attachment, $fetched['mime']);
        $stored = $this->fileStorage->storeRawContent(
            $fetched['content'],
            $userId,
            $this->uniqueStorageName($filename),
            $mime,
        );
        if (!$stored['success'] || '' === $stored['path']) {
            $this->logger->warning('Email webhook: attachment store failed', [
                'filename' => $filename,
                'error' => $stored['error'] ?? null,
            ]);

            return null;
        }

        $file = new File();
        $file->setUserId($userId);
        $file->setFilePath($stored['path']);
        $file->setFileType($extension);
        $file->setFileName($filename);
        $file->setOriginalName($filename);
        $file->setFileSize($stored['size']);
        $file->setFileMime('' !== $stored['mime'] ? $stored['mime'] : $mime);
        $file->setStatus('uploaded');
        $file->setSource('chat_attachment');
        $messageId = $message->getId();
        if (null !== $messageId) {
            $file->setMessageId($messageId);
        }

        $this->em->persist($file);
        $message->addFile($file);

        $this->logger->info('Email webhook: attachment stored', [
            'filename' => $filename,
            'path' => $stored['path'],
            'size' => $stored['size'],
            'message_id' => $messageId,
        ]);

        return $file;
    }

    /**
     * @param array<array-key, mixed> $attachment
     */
    private function filenameFrom(array $attachment, string $url): string
    {
        $name = trim((string) ($attachment['filename'] ?? ''));
        if ('' !== $name) {
            return basename(str_replace('\\', '/', $name));
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $base = basename($path);

        return '' !== $base ? $base : 'attachment.bin';
    }

    private function uniqueStorageName(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        if ('' === $basename) {
            $basename = 'attachment';
        }

        $unique = bin2hex(random_bytes(4));

        return '' !== $extension ? $basename.'_'.$unique.'.'.$extension : $basename.'_'.$unique;
    }

    /**
     * @param array<array-key, mixed> $attachment
     */
    private function mimeFrom(array $attachment, string $fetchedMime): string
    {
        if ('' !== $fetchedMime && 'application/octet-stream' !== $fetchedMime) {
            return $fetchedMime;
        }

        $declared = strtolower(trim((string) ($attachment['content_type'] ?? '')));
        if ('' === $declared) {
            return '' !== $fetchedMime ? $fetchedMime : 'application/octet-stream';
        }

        return trim(explode(';', $declared)[0]);
    }

    /**
     * @return array{content: string, mime: string}|null
     */
    private function fetchBytes(string $url, float $deadline): ?array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $remaining = $deadline - microtime(true);
            if ($remaining < 1.0) {
                $this->logger->info('Email webhook: attachment skipped (fetch budget exhausted)', [
                    'url' => FileHelper::redactUrlForLogging($current),
                ]);

                return null;
            }

            if ($this->ssrfGuard->isBlockedUrl($current)) {
                $this->logger->warning('Email webhook: attachment URL blocked', [
                    'url' => FileHelper::redactUrlForLogging($current),
                ]);

                return null;
            }

            $host = (string) parse_url($current, PHP_URL_HOST);
            $timeout = min(self::FETCH_TIMEOUT_SECONDS, $remaining);
            $options = [
                'timeout' => $timeout,
                'max_duration' => $timeout,
                'max_redirects' => 0,
                'headers' => [
                    'User-Agent' => 'SynaplanBot/1.0 (+https://synaplan.com/bot)',
                ],
            ];
            if ('' !== $host && false === filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP)) {
                $pinned = $this->ssrfGuard->pinnedIps($host);
                if ([] !== $pinned) {
                    $options['resolve'] = [$host => $pinned[0]];
                }
            }

            try {
                $response = $this->httpClient->request('GET', $current, $options);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);

                if ($status >= 300 && $status < 400) {
                    $location = trim((string) ($headers['location'][0] ?? ''));
                    if ('' === $location) {
                        $this->logger->info('Email webhook: attachment redirect without Location', [
                            'url' => FileHelper::redactUrlForLogging($current),
                            'status' => $status,
                        ]);

                        return null;
                    }
                    $current = $this->resolveRedirect($current, $location);
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    $this->logger->info('Email webhook: attachment fetch failed', [
                        'url' => FileHelper::redactUrlForLogging($current),
                        'status' => $status,
                    ]);

                    return null;
                }

                $declared = (int) ($headers['content-length'][0] ?? 0);
                if ($declared > FileStorageService::MAX_FILE_SIZE) {
                    $response->cancel();
                    $this->logger->info('Email webhook: attachment skipped (content-length too large)', [
                        'url' => FileHelper::redactUrlForLogging($current),
                        'size' => $declared,
                    ]);

                    return null;
                }

                $content = $this->readCapped($response);
                if (null === $content) {
                    $this->logger->info('Email webhook: attachment skipped (empty or too large after download)', [
                        'url' => FileHelper::redactUrlForLogging($current),
                    ]);

                    return null;
                }

                $mimeHeader = strtolower(trim((string) ($headers['content-type'][0] ?? '')));
                $mime = '' !== $mimeHeader ? trim(explode(';', $mimeHeader)[0]) : 'application/octet-stream';

                return ['content' => $content, 'mime' => $mime];
            } catch (\Throwable $e) {
                $this->logger->warning('Email webhook: attachment fetch threw', [
                    'url' => FileHelper::redactUrlForLogging($current),
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        $this->logger->info('Email webhook: attachment skipped (too many redirects)', [
            'url' => FileHelper::redactUrlForLogging($url),
        ]);

        return null;
    }

    private function readCapped(ResponseInterface $response): ?string
    {
        $content = '';
        $cap = FileStorageService::MAX_FILE_SIZE;
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isLast()) {
                break;
            }
            $content .= $chunk->getContent();
            if (strlen($content) > $cap) {
                $response->cancel();

                return null;
            }
        }

        return '' === $content ? null : $content;
    }

    private function resolveRedirect(string $current, string $location): string
    {
        $location = trim($location);
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($current);
        if (!is_array($parts)) {
            return $location;
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }

        if (str_starts_with($location, '?')) {
            return $scheme.'://'.$host.$port.$path.$location;
        }

        if (str_starts_with($location, '#')) {
            $query = isset($parts['query']) ? '?'.$parts['query'] : '';

            return $scheme.'://'.$host.$port.$path.$query.$location;
        }

        $slash = strrpos($path, '/');
        $dir = false === $slash ? '/' : substr($path, 0, $slash + 1);

        return $scheme.'://'.$host.$port.$dir.$location;
    }
}
