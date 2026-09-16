<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

use App\Service\File\VectorizationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\SelfAware\SelfAwareConfig;
use Psr\Log\LoggerInterface;

/**
 * Idempotent sync of synaplan-docs into owner 0 / SYSTEM:synaplan.
 *
 * Never called from boot (`app:seed` / entrypoint). Scheduler, messenger,
 * or an operator command only.
 */
final readonly class PlatformDocsSyncService
{
    public const GROUP_KEY = 'SYSTEM:synaplan';
    public const OWNER_ID = 0;

    public function __construct(
        private SelfAwareConfig $config,
        private PlatformDocsManifestClient $client,
        private PlatformDocsSyncState $state,
        private VectorStorageFacade $vectorStorage,
        private VectorizationService $vectorizationService,
        private LoggerInterface $logger,
    ) {
    }

    public static function buildFileId(string $slug): int
    {
        return abs(crc32('docs:'.$slug)) % 2_000_000_000;
    }

    public function sync(bool $force = false, bool $dryRun = false): DocsSyncResult
    {
        $manifestUrl = $this->config->docsManifestUrl();
        if ('' === $manifestUrl) {
            return DocsSyncResult::skipped('SELF_AWARE.DOCS_MANIFEST_URL is empty; sync disabled.');
        }

        try {
            $manifest = $this->client->fetchManifest($manifestUrl);
        } catch (\Throwable $e) {
            $this->logger->warning('Platform docs sync: manifest fetch failed', [
                'url' => $manifestUrl,
                'error' => $e->getMessage(),
            ]);

            return DocsSyncResult::failed($e->getMessage());
        }

        $previous = $this->state->read();
        $previousPages = $previous['pages'];
        $manifestSlugs = [];
        foreach ($manifest->pages as $page) {
            $manifestSlugs[$page->slug] = true;
        }

        $rows = [];
        $changed = 0;
        $unchanged = 0;
        $removed = 0;
        $failed = 0;
        $nextPages = $previousPages;

        foreach ($manifest->pages as $page) {
            $prior = $previousPages[$page->slug] ?? null;
            $isChanged = $force || null === $prior || $prior['sha256'] !== $page->sha256;
            if (!$isChanged) {
                ++$unchanged;
                $rows[] = ['slug' => $page->slug, 'action' => 'unchanged', 'chunks' => 0, 'message' => ''];
                continue;
            }

            if ($dryRun) {
                ++$changed;
                $rows[] = ['slug' => $page->slug, 'action' => 'would-change', 'chunks' => 0, 'message' => ''];
                continue;
            }

            try {
                $markdown = $this->client->fetchPage($page);
                $fileId = self::buildFileId($page->slug);
                $this->vectorStorage->deleteByFile(self::OWNER_ID, $fileId);
                $text = $this->prefixPage($page, $this->stripNoise($markdown));
                $result = $this->vectorizationService->vectorizeAndStore(
                    $text,
                    self::OWNER_ID,
                    $fileId,
                    self::GROUP_KEY,
                    0,
                );
                $chunks = (int) ($result['chunks_created'] ?? 0);
                if (empty($result['success'])) {
                    throw new \RuntimeException((string) ($result['error'] ?? 'vectorizeAndStore failed'));
                }
                $nextPages[$page->slug] = [
                    'sha256' => $page->sha256,
                    'file_id' => $fileId,
                    'title' => $page->title,
                    'url' => $page->url,
                    'section' => $page->section,
                    'synced_at' => gmdate('c'),
                    'slug' => $page->slug,
                ];
                ++$changed;
                $rows[] = ['slug' => $page->slug, 'action' => 'changed', 'chunks' => $chunks, 'message' => ''];
            } catch (\Throwable $e) {
                ++$failed;
                $rows[] = ['slug' => $page->slug, 'action' => 'failed', 'chunks' => 0, 'message' => $e->getMessage()];
                $this->logger->warning('Platform docs sync: page failed', [
                    'slug' => $page->slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($previousPages as $slug => $page) {
            if (isset($manifestSlugs[$slug])) {
                continue;
            }
            if ($dryRun) {
                ++$removed;
                $rows[] = ['slug' => $slug, 'action' => 'would-remove', 'chunks' => 0, 'message' => ''];
                continue;
            }
            $this->vectorStorage->deleteByFile(self::OWNER_ID, (int) $page['file_id']);
            unset($nextPages[$slug]);
            ++$removed;
            $rows[] = ['slug' => $slug, 'action' => 'removed', 'chunks' => 0, 'message' => ''];
        }

        if (!$dryRun) {
            $this->state->write([
                'manifest_url' => $manifestUrl,
                'manifest_version' => $manifest->version,
                'synced_at' => gmdate('c'),
                'pages' => $nextPages,
            ]);
        }

        return new DocsSyncResult(
            $failed > 0 && 0 === $changed && 0 === $removed ? 'failed' : 'ok',
            $changed,
            $unchanged,
            $removed,
            $failed,
            $rows,
        );
    }

    private function prefixPage(DocsPage $page, string $markdown): string
    {
        return "Source: {$page->url}\nTitle: {$page->title}\nSection: {$page->section}\n\n".$markdown;
    }

    private function stripNoise(string $markdown): string
    {
        $stripped = preg_replace('/<!--SYNAPLAN_MODELS_TABLE-->/', '', $markdown) ?? $markdown;

        return preg_replace('/<!--.*?-->/s', '', $stripped) ?? $stripped;
    }
}
