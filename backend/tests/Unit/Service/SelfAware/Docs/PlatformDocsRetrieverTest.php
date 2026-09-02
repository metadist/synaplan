<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware\Docs;

use App\Repository\ConfigRepository;
use App\Service\RAG\VectorSearchService;
use App\Service\SelfAware\Docs\PlatformDocsRetriever;
use App\Service\SelfAware\Docs\PlatformDocsSyncService;
use App\Service\SelfAware\Docs\PlatformDocsSyncState;
use App\Service\SelfAware\SelfAwareConfig;
use PHPUnit\Framework\TestCase;

final class PlatformDocsRetrieverTest extends TestCase
{
    public function testFlagOffDoesNotTouchSearch(): void
    {
        $search = $this->createMock(VectorSearchService::class);
        $search->expects($this->never())->method('semanticSearch');

        $retriever = new PlatformDocsRetriever(
            $this->config(docsRag: false),
            $this->stateWithPages(),
            $search,
        );

        $this->assertTrue($retriever->retrieve('WhatsApp', 2)->isEmpty());
    }

    public function testMapsHitsDropsUnknownAndDedupsBySlug(): void
    {
        $fileId = PlatformDocsSyncService::buildFileId('channels');
        $search = $this->createMock(VectorSearchService::class);
        $search->expects($this->once())
            ->method('semanticSearch')
            ->with(
                'How do I connect WhatsApp?',
                0,
                'SYSTEM:synaplan',
                5,
                0.35,
            )
            ->willReturn([
                ['file_id' => $fileId, 'chunk_text' => 'weak', 'score' => 0.4],
                ['file_id' => $fileId, 'chunk_text' => 'strong', 'score' => 0.9],
                ['file_id' => 999999, 'chunk_text' => 'unknown', 'score' => 0.99],
            ]);

        $retriever = new PlatformDocsRetriever(
            $this->config(docsRag: true),
            $this->stateWithPages($fileId),
            $search,
        );

        $hits = $retriever->retrieve('How do I connect WhatsApp?', 2);
        $this->assertCount(1, $hits->hits);
        $this->assertSame('channels', $hits->hits[0]->slug);
        $this->assertSame('strong', $hits->hits[0]->text);
        $this->assertSame(0.9, $hits->hits[0]->score);
    }

    public function testEmptyStateSkipsSearch(): void
    {
        $search = $this->createMock(VectorSearchService::class);
        $search->expects($this->never())->method('semanticSearch');

        $state = new PlatformDocsSyncState($this->emptyConfigRepo());
        $retriever = new PlatformDocsRetriever($this->config(docsRag: true), $state, $search);

        $this->assertTrue($retriever->retrieve('x', 2)->isEmpty());
    }

    private function config(bool $docsRag): SelfAwareConfig
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use ($docsRag): string {
                if (SelfAwareConfig::CONFIG_GROUP === $group && SelfAwareConfig::KEY_DOCS_RAG_ENABLED === $setting) {
                    return $docsRag ? '1' : '0';
                }

                return '1';
            }
        );

        return new SelfAwareConfig($repo);
    }

    private function stateWithPages(int $fileId = 42): PlatformDocsSyncState
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(json_encode([
            'manifest_url' => 'https://docs.synaplan.com/docs-manifest.json',
            'manifest_version' => '2026.09',
            'synced_at' => '2026-09-02T00:00:00Z',
            'pages' => [
                'channels' => [
                    'sha256' => str_repeat('a', 64),
                    'file_id' => $fileId,
                    'title' => 'Channels',
                    'url' => 'https://docs.synaplan.com/channels',
                    'section' => 'Using',
                    'synced_at' => '2026-09-02T00:00:00Z',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return new PlatformDocsSyncState($repo);
    }

    private function emptyConfigRepo(): ConfigRepository
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return $repo;
    }
}
