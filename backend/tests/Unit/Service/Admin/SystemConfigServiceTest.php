<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfigService;
use App\Service\Message\GranularTopicsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// The two collaborators are concrete classes; the test stores them as
// intersection types so PHPStan sees the MockObject API (->method(),
// ->expects()) alongside the production interface. Using `@var` PHPDoc on
// a typed property does NOT propagate the mixin in PHPStan's view, while
// native intersection-type properties do (PHP 8.1+).

/**
 * Locks down the side-effect hook that runs when an admin toggles
 * `QDRANT_SEARCH.GRANULAR_TOPICS_ENABLED` via the admin UI: the BCONFIG
 * write must always succeed first, then `GranularTopicsManager::applyState`
 * is invoked to flip BENABLED on the matching BPROMPTS rows.
 *
 * The rest of SystemConfigService (env writes, backups, connection tests,
 * etc.) is intentionally out of scope — covering it would require
 * filesystem fixtures that don't exist in the wider test suite today.
 */
final class SystemConfigServiceTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private GranularTopicsManager&MockObject $granularTopicsManager;
    private SystemConfigService $service;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->granularTopicsManager = $this->createMock(GranularTopicsManager::class);

        $this->service = new SystemConfigService(
            projectDir: sys_get_temp_dir(),
            logger: new NullLogger(),
            configRepository: $this->configRepository,
            granularTopicsManager: $this->granularTopicsManager,
        );
    }

    public function testTogglingGranularTopicsOnPersistsConfigAndFlipsPrompts(): void
    {
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(0, 'QDRANT_SEARCH', 'GRANULAR_TOPICS_ENABLED', 'true');

        $this->granularTopicsManager->expects($this->once())
            ->method('applyState')
            ->with(true)
            ->willReturn(['flipped' => ['general-chat'], 'unchanged' => [], 'missing' => []]);

        $result = $this->service->setValue('GRANULAR_TOPICS_ENABLED', 'true');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['requiresRestart']);
    }

    public function testTogglingGranularTopicsOffPersistsConfigAndFlipsPrompts(): void
    {
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(0, 'QDRANT_SEARCH', 'GRANULAR_TOPICS_ENABLED', 'false');

        $this->granularTopicsManager->expects($this->once())
            ->method('applyState')
            ->with(false)
            ->willReturn(['flipped' => ['general-chat'], 'unchanged' => [], 'missing' => []]);

        $result = $this->service->setValue('GRANULAR_TOPICS_ENABLED', 'false');

        $this->assertTrue($result['success']);
    }

    /**
     * Writing any OTHER database-backed key must not touch the granular
     * topics manager — the hook is keyed specifically on the granular
     * toggle. Without this guarantee every config write would silently
     * mutate prompt state, which is a footgun.
     */
    public function testOtherDatabaseKeysDoNotTriggerTheGranularHook(): void
    {
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(0, 'QDRANT_SEARCH', 'SYNAPSE_ROUTING_ENABLED', 'true');

        $this->granularTopicsManager->expects($this->never())->method('applyState');

        $this->service->setValue('SYNAPSE_ROUTING_ENABLED', 'true');
    }

    /**
     * Idempotent admin write: even if the manager reports nothing flipped
     * (because the prompt rows already match), the BCONFIG write itself
     * must still succeed and the operator must get the success response.
     */
    public function testTogglingGranularTopicsSucceedsWhenManagerReportsNoChanges(): void
    {
        $this->granularTopicsManager->expects($this->once())
            ->method('applyState')
            ->with(true)
            ->willReturn(['flipped' => [], 'unchanged' => ['general-chat'], 'missing' => []]);

        $result = $this->service->setValue('GRANULAR_TOPICS_ENABLED', 'true');

        $this->assertTrue($result['success']);
    }

    /**
     * The prompt-state sync is best-effort: if the manager throws (DB
     * hiccup, schema mismatch on a partially-migrated install, ...) the
     * BCONFIG row write must NOT be rolled back. From the next request
     * onwards `MessageSorter::granularTopicsEnabled()` already reads the
     * new flag and the AI-sort filter handles the gate — BENABLED on
     * BPROMPTS is belt-and-suspenders, the operator can converge with
     * `php bin/console app:seed`.
     */
    public function testManagerFailureDoesNotRollBackTheConfigWrite(): void
    {
        $this->configRepository->expects($this->once())->method('setValue');

        $this->granularTopicsManager->method('applyState')
            ->willThrowException(new \RuntimeException('Galera node down'));

        $result = $this->service->setValue('GRANULAR_TOPICS_ENABLED', 'true');

        $this->assertTrue($result['success']);
    }

    /**
     * The schema parser interprets the BCONFIG string as a boolean — the
     * hook must pass the parsed boolean (not the raw string) to the
     * manager so all variants ("true", "1", "yes", "on", "TRUE") behave
     * identically. This mirrors the existing SYNAPSE_ROUTING_ENABLED
     * parser semantics in MessageClassifier::isSynapseEnabled().
     */
    #[DataProvider('truthyValueProvider')]
    public function testTruthyConfigValuesAllResolveToManagerStateTrue(string $value): void
    {
        $this->granularTopicsManager->expects($this->once())
            ->method('applyState')
            ->with(true)
            ->willReturn(['flipped' => [], 'unchanged' => [], 'missing' => []]);

        $this->service->setValue('GRANULAR_TOPICS_ENABLED', $value);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function truthyValueProvider(): iterable
    {
        yield 'true' => ['true'];
        yield '1' => ['1'];
        yield 'yes' => ['yes'];
        yield 'on' => ['on'];
        yield 'TRUE uppercase' => ['TRUE'];
    }
}
