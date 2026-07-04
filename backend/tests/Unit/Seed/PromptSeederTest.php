<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Seed\PromptSeeder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The prompt seeder wraps PromptCatalog::seed() and reports a 'prompts'
 * SeedResult. The catalog itself is exercised by PromptCatalogTest; here we
 * verify the seeder completes against a connection and that catalog
 * `metadata` (release defaults like `general`'s tool_mcp=1) is
 * BOOTSTRAP-ONLY: written on first insert, never on re-seed of an existing
 * prompt (an operator's change — including removing a key entirely, the
 * Internet Search "auto" state — must survive every container start).
 */
final class PromptSeederTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    public function testSeedReturnsPromptsResult(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
        $this->connection->method('lastInsertId')->willReturn('42');

        $result = (new PromptSeeder($this->connection))->seed();

        $this->assertSame('prompts', $result->label);
    }

    public function testFreshInsertSeedsGeneralReleaseMetadata(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
        $this->connection->method('lastInsertId')->willReturn('42');

        $metaInserts = [];
        $this->connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$metaInserts): int {
                if (str_contains($sql, 'INSERT INTO BPROMPTMETA')) {
                    $metaInserts[] = $params;
                }

                return 1;
            },
        );

        (new PromptSeeder($this->connection))->seed();

        $byKey = [];
        foreach ($metaInserts as $params) {
            // [promptId, key, value, created]
            $byKey[$params[1]] = $params[2];
        }

        $this->assertSame('1', $byKey['tool_mcp'] ?? null, 'fresh install must ship general with MCP data sources ON');
        $this->assertArrayNotHasKey('tool_internet', $byKey, 'fresh install must leave web search on auto (key absent)');
    }

    public function testReseedOfExistingPromptsNeverTouchesMetadata(): void
    {
        // Every prompt already exists → UPDATE path for all of them.
        $this->connection->method('fetchOne')->willReturn('7');

        $this->connection->method('executeStatement')->willReturnCallback(
            static function (string $sql): int {
                self::assertStringNotContainsString(
                    'BPROMPTMETA',
                    $sql,
                    're-seeding an existing prompt must not write metadata (bootstrap-only defaults)',
                );

                return 1;
            },
        );

        (new PromptSeeder($this->connection))->seed();
    }
}
