<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Repository\ConfigRepository;
use App\Seed\PromptSeeder;
use App\Service\Message\GranularTopicsManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the post-seed convergence contract: after the catalog UPDATE
 * blindly resets BENABLED on the granular alias rows (driven by
 * `'enabled' => false` in PromptCatalog), the seeder re-applies the
 * admin's `GRANULAR_TOPICS_ENABLED` toggle so BPROMPTS state matches
 * BCONFIG state at the end of every seed.
 *
 * Without this convergence step, an admin who flips the toggle ON via
 * the UI would silently lose their setting on the very next deploy /
 * container restart that runs `app:seed`.
 */
final class PromptSeederTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private GranularTopicsManager&MockObject $granularTopicsManager;
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->granularTopicsManager = $this->createMock(GranularTopicsManager::class);
        $this->connection = $this->createMock(Connection::class);

        // Force PromptCatalog::seed() down the schema-check path used in
        // production (BPROMPTS exists, BKEYWORDS + BENABLED columns
        // present). The catalog itself is exercised by PromptCatalogTest;
        // here we just need the seed call to complete without I/O surprises
        // so we can assert what happens AFTER it.
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturnCallback(static function (string $table): array {
            if ('BPROMPTS' !== $table) {
                return [];
            }

            $columns = [];
            foreach (['BKEYWORDS', 'BENABLED'] as $name) {
                $col = new Column($name, \Doctrine\DBAL\Types\Type::getType('string'));
                $columns[$name] = $col;
            }

            return $columns;
        });
        $this->connection->method('createSchemaManager')->willReturn($schemaManager);
        $this->connection->method('fetchOne')->willReturn(false);
    }

    public function testConvergenceCallsManagerWithCurrentToggleStateOff(): void
    {
        $this->configRepository->method('getValue')
            ->with(0, GranularTopicsManager::CONFIG_GROUP, GranularTopicsManager::CONFIG_KEY)
            ->willReturn(null);

        $this->granularTopicsManager->expects($this->once())
            ->method('applyState')
            ->with(false)
            ->willReturn(['flipped' => [], 'unchanged' => [], 'missing' => []]);

        $seeder = new PromptSeeder($this->connection, $this->configRepository, $this->granularTopicsManager);
        $result = $seeder->seed();

        $this->assertSame('prompts', $result->label);
    }

    public function testConvergenceCallsManagerWithCurrentToggleStateOn(): void
    {
        // Admin previously flipped the toggle ON; the seed must NOT silently
        // un-flip the BPROMPTS rows on the next deploy. The manager call with
        // `true` re-converges the rows back to BENABLED=1 after the catalog
        // overwrote them to 0.
        $this->configRepository->method('getValue')
            ->with(0, GranularTopicsManager::CONFIG_GROUP, GranularTopicsManager::CONFIG_KEY)
            ->willReturn('true');

        $this->granularTopicsManager->expects($this->once())
            ->method('applyState')
            ->with(true)
            ->willReturn(['flipped' => ['general-chat'], 'unchanged' => [], 'missing' => []]);

        (new PromptSeeder($this->connection, $this->configRepository, $this->granularTopicsManager))->seed();
    }

    /**
     * The convergence parser must accept the same truthy variants as
     * `MessageClassifier::isSynapseEnabled()` so the two toggles behave
     * identically. Without this, an admin who set `'1'` in BCONFIG via
     * SQL would see the toggle's UI behaviour and its seed-convergence
     * behaviour disagree.
     *
     * @param non-empty-string $rawConfigValue
     */
    #[DataProvider('toggleValueProvider')]
    public function testConvergenceParsesToggleValueLikeIsSynapseEnabled(string $rawConfigValue, bool $expected): void
    {
        $this->configRepository->method('getValue')->willReturn($rawConfigValue);
        $this->granularTopicsManager->expects($this->once())
            ->method('applyState')
            ->with($expected)
            ->willReturn(['flipped' => [], 'unchanged' => [], 'missing' => []]);

        (new PromptSeeder($this->connection, $this->configRepository, $this->granularTopicsManager))->seed();
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function toggleValueProvider(): iterable
    {
        yield 'true' => ['true', true];
        yield '1' => ['1', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield 'TRUE uppercase' => ['TRUE', true];
        yield 'false' => ['false', false];
        yield '0' => ['0', false];
        yield 'no' => ['no', false];
        yield 'off' => ['off', false];
        yield 'random gibberish defaults to off' => ['banana', false];
    }
}
