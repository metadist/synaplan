<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModelListCommand;
use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ModelListCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $command = new ModelListCommand($this->connection);

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester($application->find('app:model:list'));
    }

    public function testListWithNoEnabledModels(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->method('fetchFirstColumn')->willReturn([]);

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Active', $output);
        $this->assertStringContainsString('Key', $output);
        $this->assertStringNotContainsString('yes', $output);
    }

    public function testListShowsEnabledModelAsYes(): void
    {
        // Any catalog BID will do; reading it from the catalog keeps the test
        // alive when that model is later retired.
        $enabledBid = (string) ModelCatalog::all()[0]['id'];

        // @phpstan-ignore-next-line
        $this->connection->method('fetchFirstColumn')->willReturn([$enabledBid]);

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('yes', $this->commandTester->getDisplay());
    }

    public function testListShowsAllCatalogModels(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->method('fetchFirstColumn')->willReturn([]);

        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('groq', $output);
        $this->assertStringContainsString('ollama', $output);
        $this->assertStringContainsString('openai', $output);
    }
}
