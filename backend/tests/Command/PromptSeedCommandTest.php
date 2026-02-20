<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PromptSeedCommand;
use App\Prompt\PromptCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PromptSeedCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $command = new PromptSeedCommand($this->connection);

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($application->find('app:prompt:seed'));
    }

    public function testSeedInsertsNewPrompts(): void
    {
        $promptCount = count(PromptCatalog::all());

        // fetchOne returns false (not found) → INSERT path
        // @phpstan-ignore-next-line
        $this->connection->expects($this->exactly($promptCount))
            ->method('fetchOne')
            ->willReturn(false);

        // One INSERT per prompt
        // @phpstan-ignore-next-line
        $this->connection->expects($this->exactly($promptCount))
            ->method('executeStatement');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Seeded', $output);
        $this->assertStringContainsString('general', $output);
        $this->assertStringContainsString('tools:sort', $output);
    }

    public function testSeedUpdatesExistingPrompts(): void
    {
        $promptCount = count(PromptCatalog::all());

        // fetchOne returns an existing BID → UPDATE path
        // @phpstan-ignore-next-line
        $this->connection->expects($this->exactly($promptCount))
            ->method('fetchOne')
            ->willReturn(42);

        // One UPDATE per prompt
        // @phpstan-ignore-next-line
        $this->connection->expects($this->exactly($promptCount))
            ->method('executeStatement');

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
    }

    public function testCatalogHasExpectedPrompts(): void
    {
        $prompts = PromptCatalog::all();
        $topics = array_column($prompts, 'topic');

        $this->assertContains('general', $topics);
        $this->assertContains('tools:sort', $topics);
        $this->assertContains('analyzefile', $topics);
        $this->assertContains('mediamaker', $topics);
        $this->assertContains('tools:enhance', $topics);
        $this->assertContains('tools:search', $topics);
        $this->assertContains('tools:memory_extraction', $topics);
        $this->assertGreaterThanOrEqual(17, count($prompts));
    }

    public function testCatalogPromptsHaveRequiredFields(): void
    {
        foreach (PromptCatalog::all() as $prompt) {
            $this->assertArrayHasKey('topic', $prompt);
            $this->assertArrayHasKey('language', $prompt);
            $this->assertArrayHasKey('shortDescription', $prompt);
            $this->assertArrayHasKey('prompt', $prompt);
            $this->assertNotEmpty($prompt['topic']);
            $this->assertNotEmpty($prompt['prompt']);
        }
    }
}
