<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModelDisableCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ModelDisableCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $command = new ModelDisableCommand($this->connection);

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($application->find('app:model:disable'));
    }

    public function testDisableSingleModel(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->expects($this->once())->method('executeStatement')
            ->with('DELETE FROM BMODELS WHERE BID = ?', $this->isType('array'));

        $this->commandTester->execute(['models' => ['groq:llama-3.3-70b-versatile']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Disabled 1 model(s)', $this->commandTester->getDisplay());
    }

    public function testDisableGroupedKeyRemovesAllVariants(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->expects($this->exactly(2))->method('executeStatement');

        $this->commandTester->execute(['models' => ['openai:gpt-4o']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Disabled 2 model(s)', $this->commandTester->getDisplay());
    }

    public function testDisableUnknownKeyReturnsFailure(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->expects($this->never())->method('executeStatement');

        $this->commandTester->execute(['models' => ['nonexistent:model']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Unknown model key', $this->commandTester->getDisplay());
    }

    public function testDisableMixedKnownAndUnknown(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->expects($this->once())->method('executeStatement');

        $this->commandTester->execute(['models' => ['groq:llama-3.3-70b-versatile', 'fake:nope']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Disabled 1 model(s)', $output);
        $this->assertStringContainsString('Unknown model key: fake:nope', $output);
    }
}
