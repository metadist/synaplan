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
 * just verify the seeder completes against a connection.
 */
final class PromptSeederTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('fetchOne')->willReturn(false);
    }

    public function testSeedReturnsPromptsResult(): void
    {
        $result = (new PromptSeeder($this->connection))->seed();

        $this->assertSame('prompts', $result->label);
    }
}
