<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\FileHelper;
use App\Service\File\FileStorageService;
use App\Service\Security\SsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Persists attachments posted on {@see \App\Controller\WebhookController::email()}
 * as File rows, the same storage step WhatsApp already uses for inbound media.
 *
 * Unsafe URLs, unknown types and oversized payloads are skipped; the email
 * itself is still accepted. Automatic vectorization is not started.
 */
final readonly class InboundEmailAttachmentStore
{
    private const FETCH_TIMEOUT_SECONDS = 20;
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
        private FileStorageService $fileStorage,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<mixed> $attachments
     *
     * @return list<File>
     */
    public function attach(Message $message, int $userId, array $attachments): array
    {
        $stored = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $file = $this->storeOne($message, $userId, $attachment);
            if (null !== $file) {
                $stored[] = $file;
            }
        }

        if ([] !== $stored) {
            $message->setFile(1);
        }

        return $stored;
    }

    /**
     * @param array<array-key, mixed> $attachment
     */
    private function storeOne(Message $message, int $userId, array $attachment): ?File
    {
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
        if (is_numeric($declaredSize) && (int) $declaredSize > FileStorageService::MAX_FILE_SIZE) {
            $this->logger->info('Email webhook: attachment skipped (declared size too large)', [
                'filename' => $filename,
                'size' => (int) $declaredSize,
            ]);

            return null;
        }

        $fetched = $this->fetchBytes($url);
        if (null === $fetched) {
            return null;
        }

        $mime = $this->mimeFrom($attachment, $fetched['mime']);
        $stored = $this->fileStorage->storeRawContent($fetched['content'], $userId, $filename, $mime);
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

        $this->em->persist($file);
        $message->addFile($file);

        $this->logger->info('Email webhook: attachment stored', [
            'filename' => $filename,
            'path' => $stored['path'],
            'size' => $stored['size'],
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
    private function fetchBytes(string $url): ?array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if ($this->ssrfGuard->isBlockedUrl($current)) {
                $this->logger->warning('Email webhook: attachment URL blocked', [
                    'url' => FileHelper::redactUrlForLogging($current),
                ]);

                return null;
            }

            try {
                $response = $this->httpClient->request('GET', $current, [
                    'timeout' => self::FETCH_TIMEOUT_SECONDS,
                    'max_redirects' => 0,
                    'headers' => [
                        'User-Agent' => 'SynaplanBot/1.0 (+https://synaplan.com/bot)',
                    ],
                ]);
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
                    $this->logger->info('Email webhook: attachment skipped (content-length too large)', [
                        'url' => FileHelper::redactUrlForLogging($current),
                        'size' => $declared,
                    ]);

                    return null;
                }

                $content = $response->getContent(false);
                if ('' === $content || strlen($content) > FileStorageService::MAX_FILE_SIZE) {
                    $this->logger->info('Email webhook: attachment skipped (empty or too large after download)', [
                        'url' => FileHelper::redactUrlForLogging($current),
                        'size' => strlen($content),
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

    private function resolveRedirect(string $current, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($current);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }

        $basePath = (string) ($parts['path'] ?? '/');
        $slash = strrpos($basePath, '/');
        $dir = false === $slash ? '/' : substr($basePath, 0, $slash + 1);

        return $scheme.'://'.$host.$port.$dir.$location;
    }
}
