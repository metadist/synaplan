<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ModelEnableCommand;
use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ModelEnableCommandTest extends TestCase
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

        $command = new ModelEnableCommand($this->connection);

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester($application->find('app:model:enable'));
    }

    /** Model rows absent from the database (fetchOne finds nothing). */
    private function givenModelsAreMissing(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
    }

    /** Model rows already present in the database. */
    private function givenModelsExist(): void
    {
        $this->connection->method('fetchOne')->willReturn('9');
    }

    public function testEnableMissingModelInsertsIt(): void
    {
        $this->givenModelsAreMissing();

        $this->commandTester->execute(['models' => ['groq:qwen/qwen3.6-27b:chat']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Enabled 1 model(s)', $this->commandTester->getDisplay());
        $this->assertCount(1, $this->statements);
        $this->assertStringContainsString('INSERT INTO BMODELS', $this->statements[0]);
    }

    public function testEnableExistingModelRestoresVisibilityFlagsOnly(): void
    {
        $this->givenModelsExist();

        $this->commandTester->execute(['models' => ['groq:qwen/qwen3.6-27b:chat']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertCount(1, $this->statements);
        $this->assertStringContainsString('UPDATE BMODELS SET BACTIVE = ?, BSELECTABLE = ?', $this->statements[0]);
        // Catalog-owned columns (price, name) must survive an enable untouched.
        $this->assertStringNotContainsString('BPRICEIN', $this->statements[0]);
    }

    public function testEnableMultipleModels(): void
    {
        $this->givenModelsAreMissing();

        $this->commandTester->execute(['models' => ['groq:qwen/qwen3.6-27b:chat', 'ollama:bge-m3']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Enabled 2 model(s)', $this->commandTester->getDisplay());
        $this->assertCount(2, $this->statements);
    }

    public function testEnableGroupedKeyEnablesAllVariants(): void
    {
        // google:gemini-2.5-pro resolves to chat + pic2text
        $this->givenModelsAreMissing();

        $this->commandTester->execute(['models' => ['google:gemini-2.5-pro']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Enabled 2 model(s)', $this->commandTester->getDisplay());
    }

    public function testEnableByProviderEnablesEveryNonRetiredCatalogModel(): void
    {
        $this->givenModelsAreMissing();

        $this->commandTester->execute(['--provider' => ['groq']]);

        $expected = count(array_filter(
            ModelCatalog::findByService('groq'),
            static fn (array $model): bool => !ModelCatalog::isRetired($model['id'])
        ));
        $this->assertGreaterThan(0, $expected, 'fixture assumption: the catalog has Groq models');

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertStringContainsString("Enabled $expected model(s)", $this->commandTester->getDisplay());
        $this->assertCount($expected, $this->statements);
    }

    public function testEnableByProviderAndExplicitKeyDeduplicates(): void
    {
        $this->givenModelsAreMissing();

        $this->commandTester->execute([
            'models' => ['ollama:bge-m3'],
            '--provider' => ['ollama'],
        ]);

        $expected = count(array_filter(
            ModelCatalog::findByService('ollama'),
            static fn (array $model): bool => !ModelCatalog::isRetired($model['id'])
        ));

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $this->assertCount($expected, $this->statements, 'the explicit key must not be enabled twice');
    }

    public function testEnableRetiredModelIsSkipped(): void
    {
        // xai:grok-tts (BID 320) is kept in the catalog but recorded as retired.
        $this->givenModelsAreMissing();

        $this->commandTester->execute(['models' => ['xai:grok-tts']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Skipped (retired)', $output);
        $this->assertStringNotContainsString('Enabled 1', $output);
        $this->assertCount(0, $this->statements);
    }

    public function testEnableUnknownKeyReturnsFailure(): void
    {
        $this->commandTester->execute(['models' => ['nonexistent:model']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Unknown model key', $this->commandTester->getDisplay());
        $this->assertCount(0, $this->statements);
    }

    public function testEnableUnknownProviderReturnsFailure(): void
    {
        $this->commandTester->execute(['--provider' => ['skynet']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Unknown provider: skynet', $output);
        $this->assertStringContainsString('Known providers:', $output);
        $this->assertCount(0, $this->statements);
    }

    public function testEnableMixedKnownAndUnknown(): void
    {
        $this->givenModelsAreMissing();

        $this->commandTester->execute(['models' => ['groq:qwen/qwen3.6-27b:chat', 'fake:nope']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Enabled 1 model(s)', $output);
        $this->assertStringContainsString('Unknown model key: fake:nope', $output);
    }

    public function testNoArgumentsIsRejected(): void
    {
        $this->commandTester->execute([]);

        $this->assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('--provider', $this->commandTester->getDisplay());
        $this->assertCount(0, $this->statements);
    }

    public function testEnableOnlyEnablesListedProvidersAndDisablesTheRest(): void
    {
        $this->givenModelsAreMissing();

        $this->commandTester->execute(['--only' => ['ollama', 'piper']]);

        $this->assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

        $allow = ['ollama', 'piper'];
        $expectedEnabled = 0;
        $expectedDisabled = 0;
        foreach (array_keys(ModelCatalog::serviceNames()) as $service) {
            foreach (ModelCatalog::findByService($service) as $model) {
                if (in_array($service, $allow, true)) {
                    if (!ModelCatalog::isRetired($model['id'])) {
                        ++$expectedEnabled;
                    }
                } else {
                    ++$expectedDisabled;
                }
            }
        }

        $this->assertGreaterThan(0, $expectedEnabled);
        $this->assertGreaterThan(0, $expectedDisabled);
        $this->assertNotEmpty($this->statements);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString(
            sprintf('enabled %d model(s), disabled %d from other providers', $expectedEnabled, $expectedDisabled),
            $output
        );
        $this->assertStringNotContainsString('DELETE FROM', implode("\n", $this->statements));
    }

    public function testEnableOnlyRejectsUnknownProvider(): void
    {
        $this->commandTester->execute(['--only' => ['skynet']]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Unknown provider: skynet', $this->commandTester->getDisplay());
        $this->assertCount(0, $this->statements);
    }

    public function testEnableOnlyCannotMixWithProviderOrKeys(): void
    {
        $this->commandTester->execute([
            '--only' => ['ollama'],
            '--provider' => ['groq'],
        ]);

        $this->assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('cannot be combined', $this->commandTester->getDisplay());
        $this->assertCount(0, $this->statements);
    }
}
