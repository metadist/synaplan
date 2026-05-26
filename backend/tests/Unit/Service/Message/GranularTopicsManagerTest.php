<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\Message\GranularTopicsManager;
use App\Service\Message\TopicAliasResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Locks down the contract between the `QDRANT_SEARCH.GRANULAR_TOPICS_ENABLED`
 * admin toggle and the BENABLED flag on the granular routing alias rows
 * in BPROMPTS. The manager is the bridge: SystemConfigService::setValue()
 * calls into it after writing the BCONFIG row.
 */
final class GranularTopicsManagerTest extends TestCase
{
    private PromptRepository&MockObject $promptRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private GranularTopicsManager $manager;

    protected function setUp(): void
    {
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->manager = new GranularTopicsManager(
            new TopicAliasResolver(),
            $this->promptRepository,
            $this->entityManager,
            new NullLogger(),
        );
    }

    public function testApplyStateEnablesEverySeededAliasRow(): void
    {
        // Pretend every alias has a single disabled `en` row seeded.
        $rows = [];
        foreach (
            ['general-chat', 'coding', 'image-generation', 'video-generation', 'audio-generation'] as $topic
        ) {
            $row = $this->makePrompt($topic, enabled: false);
            $rows[$topic] = $row;
        }

        $this->promptRepository->method('findAllByTopicAndOwner')
            ->willReturnCallback(static fn (string $topic, int $ownerId): array => isset($rows[$topic]) && 0 === $ownerId
                ? [$rows[$topic]]
                : []);

        // Every row must be persisted, and exactly ONE flush at the end —
        // the toggle is one transaction from the operator's POV.
        $this->entityManager->expects($this->exactly(5))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $report = $this->manager->applyState(true);

        $this->assertSame(
            ['general-chat', 'coding', 'image-generation', 'video-generation', 'audio-generation'],
            $report['flipped']
        );
        $this->assertSame([], $report['unchanged']);
        $this->assertSame([], $report['missing']);

        foreach ($rows as $row) {
            $this->assertTrue($row->isEnabled());
        }
    }

    public function testApplyStateDisablesEverySeededAliasRow(): void
    {
        $rows = [];
        foreach (
            ['general-chat', 'coding', 'image-generation', 'video-generation', 'audio-generation'] as $topic
        ) {
            $rows[$topic] = $this->makePrompt($topic, enabled: true);
        }

        $this->promptRepository->method('findAllByTopicAndOwner')
            ->willReturnCallback(static fn (string $topic): array => isset($rows[$topic]) ? [$rows[$topic]] : []);

        $this->entityManager->expects($this->exactly(5))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $report = $this->manager->applyState(false);

        $this->assertCount(5, $report['flipped']);

        foreach ($rows as $row) {
            $this->assertFalse($row->isEnabled());
        }
    }

    /**
     * Rows already in the target state must not be persisted and there
     * must be no flush — the operation is purely idempotent. This is the
     * key property that lets `SystemConfigService::setValue()` call into
     * the manager unconditionally on every BCONFIG write of the key.
     */
    public function testApplyStateIsIdempotentWhenAllRowsAlreadyMatch(): void
    {
        $rows = [];
        foreach (
            ['general-chat', 'coding', 'image-generation', 'video-generation', 'audio-generation'] as $topic
        ) {
            $rows[$topic] = $this->makePrompt($topic, enabled: false);
        }

        $this->promptRepository->method('findAllByTopicAndOwner')
            ->willReturnCallback(static fn (string $topic): array => isset($rows[$topic]) ? [$rows[$topic]] : []);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $report = $this->manager->applyState(false);

        $this->assertSame([], $report['flipped']);
        $this->assertCount(5, $report['unchanged']);
    }

    /**
     * Topics flip every per-language variant (en/de/...) in a single pass.
     * The seed schema permits multiple rows per (owner, topic) pair so the
     * manager must touch ALL of them, not just the freshest.
     */
    public function testApplyStateFlipsEveryLanguageVariantOfATopic(): void
    {
        $en = $this->makePrompt('general-chat', enabled: false, language: 'en');
        $de = $this->makePrompt('general-chat', enabled: false, language: 'de');

        $this->promptRepository->method('findAllByTopicAndOwner')
            ->willReturnCallback(static function (string $topic) use ($en, $de): array {
                if ('general-chat' === $topic) {
                    return [$en, $de];
                }

                return [];
            });

        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $report = $this->manager->applyState(true);

        $this->assertTrue($en->isEnabled());
        $this->assertTrue($de->isEnabled());

        // Other aliases produce no seeded row -> reported as missing, not
        // flipped. We only assert that general-chat was flipped here.
        $this->assertContains('general-chat', $report['flipped']);
    }

    /**
     * Fresh install before `app:seed`: the catalog rows aren't there yet.
     * The manager must not throw — it should simply report the topics it
     * couldn't find so the operator/log has a record. The next seed run
     * will create the rows with the catalog's own ship-disabled flag.
     */
    public function testApplyStateReportsMissingAliasesWithoutThrowing(): void
    {
        $this->promptRepository->method('findAllByTopicAndOwner')->willReturn([]);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $report = $this->manager->applyState(true);

        $this->assertSame([], $report['flipped']);
        $this->assertSame([], $report['unchanged']);
        $this->assertSame(
            ['general-chat', 'coding', 'image-generation', 'video-generation', 'audio-generation'],
            $report['missing']
        );
    }

    /**
     * The manager only touches BOWNERID=0 rows. User-created prompts that
     * happen to share a granular topic name (e.g. a user's custom
     * `general-chat` override) must not be flipped — the toggle is a
     * system-default switch, not an admin override mechanism.
     */
    public function testApplyStateOnlyLoadsSystemOwnedRows(): void
    {
        $this->promptRepository->expects($this->atLeastOnce())
            ->method('findAllByTopicAndOwner')
            ->with($this->isType('string'), 0)
            ->willReturn([]);

        $this->manager->applyState(true);
    }

    private function makePrompt(string $topic, bool $enabled, string $language = 'en'): Prompt
    {
        $prompt = new Prompt();
        $prompt->setOwnerId(0);
        $prompt->setLanguage($language);
        $prompt->setTopic($topic);
        $prompt->setShortDescription('test');
        $prompt->setPrompt('test');
        $prompt->setEnabled($enabled);

        return $prompt;
    }
}
