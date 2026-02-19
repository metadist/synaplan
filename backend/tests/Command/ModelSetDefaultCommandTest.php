<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModelSetDefaultCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ModelSetDefaultCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $command = new ModelSetDefaultCommand($this->connection);

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($application->find('app:model:set-default'));
    }

    public function testSetDefaultSingleCapability(): void
    {
        // DELETE + INSERT = 2 calls
        // @phpstan-ignore-next-line
        $this->connection->expects($this->exactly(2))->method('executeStatement');

        $this->commandTester->execute(['model' => 'ollama:bge-m3', 'capabilities' => ['vectorize']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Set default', $this->commandTester->getDisplay());
    }

    public function testSetDefaultMultipleCapabilities(): void
    {
        // 2 capabilities × 2 SQL calls each = 4
        // @phpstan-ignore-next-line
        $this->connection->expects($this->exactly(4))->method('executeStatement');

        $this->commandTester->execute(['model' => 'groq:llama-3.3-70b-versatile', 'capabilities' => ['chat', 'sort']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('chat', $output);
        $this->assertStringContainsString('sort', $output);
    }

    public function testUnknownModelReturnsFailure(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->expects($this->never())->method('executeStatement');

        $this->commandTester->execute(['model' => 'nonexistent:model', 'capabilities' => ['chat']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Unknown model key', $this->commandTester->getDisplay());
    }

    public function testInvalidCapabilityReturnsFailure(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->expects($this->never())->method('executeStatement');

        $this->commandTester->execute(['model' => 'ollama:bge-m3', 'capabilities' => ['bogus']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Unknown capability', $this->commandTester->getDisplay());
    }

    public function testMixedValidAndInvalidCapabilities(): void
    {
        // 1 valid capability × 2 SQL calls = 2
        // @phpstan-ignore-next-line
        $this->connection->expects($this->exactly(2))->method('executeStatement');

        $this->commandTester->execute(['model' => 'ollama:bge-m3', 'capabilities' => ['vectorize', 'bogus']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Set default', $output);
        $this->assertStringContainsString('Unknown capability', $output);
    }

    public function testIncompatibleModelCapabilityReturnsFailure(): void
    {
        // @phpstan-ignore-next-line
        $this->connection->expects($this->never())->method('executeStatement');

        // ollama:bge-m3 is a vectorize model, incompatible with chat
        $this->commandTester->execute(['model' => 'ollama:bge-m3', 'capabilities' => ['chat']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('incompatible', $this->commandTester->getDisplay());
    }
}
