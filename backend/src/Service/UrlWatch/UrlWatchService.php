<?php

declare(strict_types=1);

namespace App\Service\UrlWatch;

use App\Entity\UrlWatch;
use App\Repository\UrlWatchRepository;
use App\Service\File\FileHelper;
use App\Service\Security\SsrfGuard;
use App\Service\UrlContentService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One saved page per owner+URL. Each remember()/refresh() overwrites.
 */
final readonly class UrlWatchService
{
    public const MAX_BODY_CHARS = 64000;
    private const PREVIEW_CHARS = 200;
    private const FIRST_SAVE_BODY_CHARS = 4000;

    public function __construct(
        private UrlWatchRepository $watches,
        private EntityManagerInterface $em,
        private UrlContentService $urlContent,
        private TextDiff $diff,
        private SsrfGuard $ssrfGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<UrlWatch>
     */
    public function list(int $ownerId): array
    {
        return $this->watches->findByOwner($ownerId);
    }

    public function get(int $id, int $ownerId): ?UrlWatch
    {
        return $this->watches->findOneForOwner($id, $ownerId);
    }

    /**
     * @return array{watch: UrlWatch, created: bool}
     */
    public function register(int $ownerId, string $url): array
    {
        $normalized = $this->canonicalize($url);
        if ($this->ssrfGuard->isBlockedUrl($normalized)) {
            throw new \InvalidArgumentException('blocked_url');
        }
        $hash = self::hashUrl($normalized);
        $existing = $this->watches->findOneByOwnerAndHash($ownerId, $hash);
        if ($existing instanceof UrlWatch) {
            return ['watch' => $existing, 'created' => false];
        }

        $watch = new UrlWatch($ownerId, $normalized, $hash);
        $this->em->persist($watch);
        $this->em->flush();

        return ['watch' => $watch, 'created' => true];
    }

    public function remember(int $ownerId, string $url, string $title, string $body): UrlWatchCompareResult
    {
        $normalized = $this->canonicalize($url);
        if ($this->ssrfGuard->isBlockedUrl($normalized)) {
            throw new \InvalidArgumentException('blocked_url');
        }
        $hash = self::hashUrl($normalized);
        $body = $this->clip($body, self::MAX_BODY_CHARS);
        $contentHash = hash('sha256', $body);

        $watch = $this->watches->findOneByOwnerAndHash($ownerId, $hash);
        $now = time();
        if (!$watch instanceof UrlWatch) {
            $watch = new UrlWatch($ownerId, $normalized, $hash);
            $this->em->persist($watch);
            $status = UrlWatchCompareResult::FIRST;
            $diff = '';
        } elseif (null === $watch->getFetchedAt() || '' === (string) $watch->getBody()) {
            $status = UrlWatchCompareResult::FIRST;
            $diff = '';
        } elseif ($watch->getContentHash() === $contentHash) {
            $status = UrlWatchCompareResult::UNCHANGED;
            $diff = '';
        } else {
            $status = UrlWatchCompareResult::CHANGED;
            $diff = $this->diff->unified((string) $watch->getBody(), $body);
        }

        $watch->replaceSnapshot($title, $body, $contentHash, $now);
        $watch->setLastDiffText(UrlWatchCompareResult::CHANGED === $status ? $diff : null);
        $this->em->flush();

        return new UrlWatchCompareResult(
            $status,
            $watch,
            $diff,
            $this->promptText($status, $watch, $diff, $body),
        );
    }

    public function refresh(int $id, int $ownerId): UrlWatchCompareResult
    {
        $watch = $this->watches->findOneForOwner($id, $ownerId);
        if (!$watch instanceof UrlWatch) {
            throw new UrlWatchNotFoundException();
        }

        $result = $this->urlContent->fetchForCrawling($watch->getUrl());
        if (!$result->success) {
            $reason = $result->error ?? 'fetch failed';
            $this->logger->warning('URL watch fetch failed', [
                'watch_id' => $watch->getId(),
                'owner_id' => $ownerId,
                'url' => FileHelper::redactUrlForLogging($watch->getUrl()),
                'error' => $reason,
            ]);
            $watch->recordFailure($reason);
            $this->em->flush();
            throw new UrlWatchFetchFailedException($reason);
        }

        return $this->remember($ownerId, $watch->getUrl(), $result->title, $result->extractedText);
    }

    public function delete(int $id, int $ownerId): bool
    {
        $watch = $this->watches->findOneForOwner($id, $ownerId);
        if (!$watch instanceof UrlWatch) {
            return false;
        }

        $this->em->remove($watch);
        $this->em->flush();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toListItem(UrlWatch $watch): array
    {
        return [
            'id' => (int) $watch->getId(),
            'url' => $watch->getUrl(),
            'title' => $watch->getTitle(),
            'preview' => $this->preview((string) $watch->getBody()),
            'fetchedAt' => $this->iso($watch->getFetchedAt()),
            'created' => $this->iso($watch->getCreated()),
            'updated' => $this->iso($watch->getUpdated()),
            'lastDiffText' => $watch->getLastDiffText(),
            'lastError' => $watch->getLastError(),
            'lastFailedAt' => $this->iso($watch->getLastFailedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetail(UrlWatch $watch): array
    {
        return [
            ...$this->toListItem($watch),
            'body' => $watch->getBody() ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toComparePayload(UrlWatchCompareResult $result): array
    {
        return [
            'watch' => $this->toDetail($result->watch),
            'compare' => [
                'status' => $result->status,
                'diffText' => $result->diffText,
            ],
        ];
    }

    /**
     * Lowercase host, strip fragment and default ports, consistent trailing slash.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || '' === (string) $parts['host']) {
            throw new \InvalidArgumentException('invalid_url');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('invalid_url');
        }

        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '/';
        if ('/' !== $path) {
            $path = rtrim($path, '/');
            if ('' === $path) {
                $path = '/';
            }
        }
        $query = isset($parts['query']) && '' !== (string) $parts['query']
            ? '?'.$parts['query']
            : '';

        $authority = $host;
        if (is_int($port) && !(('https' === $scheme && 443 === $port) || ('http' === $scheme && 80 === $port))) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority.$path.$query;
    }

    public static function hashUrl(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    public function canonicalize(string $raw): string
    {
        $found = $this->urlContent->extractUrls($raw);
        if ([] === $found) {
            throw new \InvalidArgumentException('invalid_url');
        }

        return self::normalizeUrl($found[0]);
    }

    private function promptText(string $status, UrlWatch $watch, string $diff, string $newBody): string
    {
        $when = null !== $watch->getFetchedAt()
            ? gmdate('Y-m-d H:i:s', $watch->getFetchedAt()).' UTC'
            : 'never';
        $header = sprintf(
            "# URL watch: %s\nStatus: %s\nLast saved: %s\n",
            $watch->getUrl(),
            $status,
            $when,
        );

        return match ($status) {
            UrlWatchCompareResult::FIRST => $header."\n## Saved page\n".$this->clip($newBody, self::FIRST_SAVE_BODY_CHARS),
            UrlWatchCompareResult::UNCHANGED => $header."\nNo changes since the last saved version.",
            UrlWatchCompareResult::CHANGED => $header."\n## Differences\n```diff\n".$diff."\n```",
            default => $header,
        };
    }

    private function preview(string $body): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return mb_substr($flat, 0, self::PREVIEW_CHARS);
    }

    private function clip(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max).'…';
    }

    private function iso(?int $ts): ?string
    {
        if (null === $ts) {
            return null;
        }

        return gmdate('c', $ts);
    }
}
