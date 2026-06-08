<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Seed\PromptSeeder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The prompt seeder wraps PromptCatalog::seed() and reports a 'prompts'
 * SeedResult. The catalog itself is exercised by PromptCatalogTest; here we
 * just verify the seeder completes against a schema-present connection.
 */
final class PromptSeederTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturnCallback(static function (string $table): array {
            if ('BPROMPTS' !== $table) {
                return [];
            }

            $columns = [];
            foreach (['BKEYWORDS', 'BENABLED'] as $name) {
                $columns[$name] = new Column($name, \Doctrine\DBAL\Types\Type::getType('string'));
            }

            return $columns;
        });
        $this->connection->method('createSchemaManager')->willReturn($schemaManager);
        $this->connection->method('fetchOne')->willReturn(false);
    }

    public function testSeedReturnsPromptsResult(): void
    {
        $result = (new PromptSeeder($this->connection))->seed();

        $this->assertSame('prompts', $result->label);
    }
}
