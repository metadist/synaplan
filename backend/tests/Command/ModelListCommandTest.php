<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModelListCommand;
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

    /**
     * @param array<int|string, int|string> $activeByBid
     */
    private function givenRows(array $activeByBid): void
    {
        // @phpstan-ignore-next-line
        $this->connection->method('fetchAllKeyValue')->willReturn($activeByBid);
    }

    public function testListWithNoEnabledModels(): void
    {
        $this->givenRows([]);

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Active', $output);
        $this->assertStringContainsString('Key', $output);
        $this->assertStringNotContainsString('yes', $output);
    }

    public function testListShowsEnabledModelAsYes(): void
    {
        // Enable the Groq Qwen 3.6 27B chat model (BID=324). Has to be a BID the
        // catalog still carries — the command only renders catalog entries.
        $this->givenRows(['324' => '1']);

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('yes', $this->commandTester->getDisplay());
    }

    /**
     * app:model:disable keeps the row and only clears BACTIVE, so row
     * existence must never be reported as "active".
     */
    public function testListShowsASoftDisabledModelAsNo(): void
    {
        $this->givenRows(['324' => '0']);

        $this->commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringNotContainsString('yes', $this->commandTester->getDisplay());
    }

    public function testListShowsAllCatalogModels(): void
    {
        $this->givenRows([]);

        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('groq', $output);
        $this->assertStringContainsString('ollama', $output);
        $this->assertStringContainsString('openai', $output);
    }
}
