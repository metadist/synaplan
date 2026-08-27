<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModelDisableCommand;
use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ModelDisableCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private Connection&MockObject $connection;

    /** @var list<string> every SQL statement the command issued */
    private array $statements = [];

    protected function setUp(): void
    {
        $this->statements = [];

        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                $this->statements[] = $sql;

                return 1;
            });

        $command = new ModelDisableCommand($this->connection);

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester($application->find('app:model:disable'));
    }

    private function givenModelsExist(): void
    {
        $this->connection->method('fetchOne')->willReturn('9');
    }

    private function givenModelsAreMissing(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
    }

    public function testDisableDeactivatesInsteadOfDeleting(): void
    {
        $this->givenModelsExist();

        $this->commandTester->execute(['models' => ['groq:qwen/qwen3.6-27b:chat']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Disabled 1 model(s)', $this->commandTester->getDisplay());
        $this->assertCount(1, $this->statements);
        $this->assertStringContainsString('UPDATE BMODELS SET BACTIVE = 0, BSELECTABLE = 0', $this->statements[0]);
        $this->assertStringNotContainsString('DELETE', $this->statements[0]);
    }

    public function testDisableMissingModelInsertsItDeactivated(): void
    {
        // A model that is not in the database yet is inserted first so the
        // deactivation survives the next app:seed (an absent row would be
        // re-inserted active).
        $this->givenModelsAreMissing();

        $this->commandTester->execute(['models' => ['groq:qwen/qwen3.6-27b:chat']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertCount(2, $this->statements);
        $this->assertStringContainsString('INSERT INTO BMODELS', $this->statements[0]);
        $this->assertStringContainsString('UPDATE BMODELS SET BACTIVE = 0, BSELECTABLE = 0', $this->statements[1]);
    }

    public function testDisableGroupedKeyDeactivatesAllVariants(): void
    {
        $this->givenModelsExist();

        $this->commandTester->execute(['models' => ['google:gemini-2.5-pro']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Disabled 2 model(s)', $this->commandTester->getDisplay());
        $this->assertCount(2, $this->statements);
    }

    public function testDisableByProviderDeactivatesEveryCatalogModel(): void
    {
        $this->givenModelsExist();

        $this->commandTester->execute(['--provider' => ['openai']]);

        $expected = count(ModelCatalog::findByService('openai'));
        $this->assertGreaterThan(0, $expected, 'fixture assumption: the catalog has OpenAI models');

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString("Disabled $expected model(s)", $this->commandTester->getDisplay());
        $this->assertCount($expected, $this->statements);
        foreach ($this->statements as $sql) {
            $this->assertStringNotContainsString('DELETE', $sql);
        }
    }

    public function testDisableUnknownKeyReturnsFailure(): void
    {
        $this->commandTester->execute(['models' => ['nonexistent:model']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Unknown model key', $this->commandTester->getDisplay());
        $this->assertCount(0, $this->statements);
    }

    public function testDisableUnknownProviderReturnsFailure(): void
    {
        $this->commandTester->execute(['--provider' => ['skynet']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Unknown provider: skynet', $this->commandTester->getDisplay());
        $this->assertCount(0, $this->statements);
    }

    public function testDisableMixedKnownAndUnknown(): void
    {
        $this->givenModelsExist();

        $this->commandTester->execute(['models' => ['groq:qwen/qwen3.6-27b:chat', 'fake:nope']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Disabled 1 model(s)', $output);
        $this->assertStringContainsString('Unknown model key: fake:nope', $output);
    }

    public function testNoArgumentsIsRejected(): void
    {
        $this->commandTester->execute([]);

        $this->assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('--provider', $this->commandTester->getDisplay());
        $this->assertCount(0, $this->statements);
    }
}
