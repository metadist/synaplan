<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Prompt;
use App\Entity\PromptMeta;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Service\PromptService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the tool-flag key normalisation contract introduced to fix
 * the silent-toggle bug: historic Vue config wrote
 * `tool_internet_search` / `tool_files_search` while every routing
 * component reads the canonical `tool_internet` / `tool_files`.
 *
 * The contract is:
 *  - Reads fold any legacy alias onto the canonical key, so the router
 *    always sees a single name.
 *  - Writes likewise fold aliases onto the canonical key so newly
 *    persisted rows never re-introduce the legacy form.
 *  - When both alias and canonical key are present in the same payload
 *    the canonical value wins (frontend can't accidentally clobber an
 *    explicit canonical update with a stale alias).
 */
final class PromptServiceTest extends TestCase
{
    private PromptMetaRepository&MockObject $promptMetaRepository;
    private EntityManagerInterface&MockObject $em;
    private PromptService $service;

    protected function setUp(): void
    {
        $promptRepository = $this->createMock(PromptRepository::class);
        $this->promptMetaRepository = $this->createMock(PromptMetaRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->service = new PromptService(
            $promptRepository,
            $this->promptMetaRepository,
            $this->em,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testLoadFoldsLegacyToolInternetSearchOntoCanonicalKey(): void
    {
        $meta = $this->makeMeta('tool_internet_search', '1');

        $this->promptMetaRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(['promptId' => 42])
            ->willReturn([$meta]);

        $loaded = $this->service->loadMetadataForPrompt(42);

        self::assertTrue(
            $loaded['tool_internet'],
            'Legacy `tool_internet_search=1` row must be exposed as canonical `tool_internet=true` so the router triggers web search',
        );
        // The canonical key is the only one routing code may rely on, so we
        // do NOT mirror it back to the legacy name (would be ambiguous).
        self::assertArrayNotHasKey('tool_internet_search', $loaded);
    }

    public function testLoadFoldsLegacyToolFilesSearchOntoCanonicalKey(): void
    {
        $meta = $this->makeMeta('tool_files_search', '1');

        $this->promptMetaRepository
            ->method('findBy')
            ->willReturn([$meta]);

        $loaded = $this->service->loadMetadataForPrompt(7);

        self::assertTrue($loaded['tool_files']);
        self::assertArrayNotHasKey('tool_files_search', $loaded);
    }

    public function testLoadPreservesAlreadyCanonicalKey(): void
    {
        $this->promptMetaRepository->method('findBy')->willReturn([
            $this->makeMeta('tool_internet', '1'),
            $this->makeMeta('tool_files', '0'),
        ]);

        $loaded = $this->service->loadMetadataForPrompt(1);

        self::assertTrue($loaded['tool_internet']);
        self::assertFalse($loaded['tool_files']);
    }

    public function testCanonicalizeMetadataKeyIsPureForUnknownKeys(): void
    {
        self::assertSame(
            'tool_internet',
            PromptService::canonicalizeMetadataKey('tool_internet_search'),
        );
        self::assertSame(
            'tool_files',
            PromptService::canonicalizeMetadataKey('tool_files_search'),
        );
        self::assertSame(
            'tool_url_screenshot',
            PromptService::canonicalizeMetadataKey('tool_url_screenshot'),
            'Non-alias keys must pass through unchanged',
        );
        self::assertSame(
            'aiModel',
            PromptService::canonicalizeMetadataKey('aiModel'),
        );
    }

    public function testSavePersistsCanonicalKeyForLegacyPayload(): void
    {
        $prompt = $this->makePrompt(99);
        $this->promptMetaRepository->method('findBy')->willReturn([]);
        $persisted = [];
        $this->em
            ->expects(self::atLeastOnce())
            ->method('persist')
            ->willReturnCallback(function (PromptMeta $meta) use (&$persisted): void {
                $persisted[$meta->getMetaKey()] = $meta->getMetaValue();
            });

        $this->service->saveMetadataForPrompt($prompt, [
            'tool_internet_search' => true,
            'tool_files_search' => false,
        ]);

        self::assertArrayHasKey(
            'tool_internet',
            $persisted,
            'Legacy `tool_internet_search` payload must be persisted as canonical `tool_internet`',
        );
        self::assertSame('1', $persisted['tool_internet']);
        self::assertArrayHasKey('tool_files', $persisted);
        self::assertSame('0', $persisted['tool_files']);
        self::assertArrayNotHasKey('tool_internet_search', $persisted);
        self::assertArrayNotHasKey('tool_files_search', $persisted);
    }

    public function testSaveCanonicalKeyWinsWhenBothAreProvided(): void
    {
        $prompt = $this->makePrompt(1);
        $this->promptMetaRepository->method('findBy')->willReturn([]);
        $persisted = [];
        $this->em
            ->method('persist')
            ->willReturnCallback(function (PromptMeta $meta) use (&$persisted): void {
                $persisted[$meta->getMetaKey()] = $meta->getMetaValue();
            });

        // Mixed payload: an explicit canonical update must not be silently
        // clobbered by a stale alias arriving in the same request body.
        $this->service->saveMetadataForPrompt($prompt, [
            'tool_internet_search' => false,
            'tool_internet' => true,
        ]);

        self::assertSame(
            '1',
            $persisted['tool_internet'] ?? null,
            'Canonical value must win when both alias and canonical key are present',
        );
        self::assertCount(1, $persisted, 'Alias must not produce a second row');
    }

    private function makeMeta(string $key, string $value): PromptMeta
    {
        $meta = new PromptMeta();
        $meta->setMetaKey($key);
        $meta->setMetaValue($value);

        return $meta;
    }

    private function makePrompt(int $id): Prompt
    {
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getId')->willReturn($id);

        return $prompt;
    }
}
