<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SetupResetCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A development aid that deletes every account, so the guard is the part worth
 * testing: running this against a real installation would wipe it and hand the
 * reopened wizard to whoever loads the URL first.
 */
#[AllowMockObjectsWithoutExpectations]
final class SetupResetCommandTest extends TestCase
{
    private Connection&MockObject $connection;

    /** @var list<string> tables the fake information_schema lookup reports */
    private array $childTables = [];

    /** @var list<string> every SQL string passed to prepare() */
    private array $prepared = [];

    /** @var list<string> every SQL string passed to executeStatement() */
    private array $executed = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('fetchOne')->willReturn(3);
        $this->connection
            ->method('prepare')
            ->willReturnCallback(function (string $sql): Statement {
                $this->prepared[] = $sql;

                return $this->statementReturning($this->childTables);
            });
        $this->connection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                $this->executed[] = $sql;

                return 0;
            });
    }

    public function testItRefusesToRunInProduction(): void
    {
        $this->connection->expects($this->never())->method('executeStatement');

        $tester = $this->tester('prod');
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('APP_ENV=prod', $tester->getDisplay());
        $this->assertStringContainsString(
            'app:admin:reset-password',
            $tester->getDisplay(),
            'the refusal has to name the supported way back in'
        );
    }

    /** Anything that is not explicitly dev or test is treated as production. */
    public function testItRefusesAnUnknownEnvironment(): void
    {
        $this->connection->expects($this->never())->method('executeStatement');

        $tester = $this->tester('staging');
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testItClearsChildTablesBeforeTheUserTable(): void
    {
        $this->childTables = ['BTOKENS', 'BWIDGETS'];

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $deletes = array_values(array_filter(
            $this->executed,
            static fn (string $sql): bool => str_starts_with($sql, 'DELETE FROM B')
        ));

        $this->assertSame(
            ['DELETE FROM BTOKENS', 'DELETE FROM BWIDGETS', 'DELETE FROM BUSER'],
            $deletes,
            'a child row outliving its user re-attaches to the next account, which gets BID 1'
        );
    }

    public function testItRestoresForeignKeyChecksAndResetsTheCounter(): void
    {
        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertSame('SET FOREIGN_KEY_CHECKS = 0', $this->executed[0]);
        $this->assertContains('SET FOREIGN_KEY_CHECKS = 1', $this->executed);
        $this->assertContains('ALTER TABLE BUSER AUTO_INCREMENT = 1', $this->executed);
    }

    public function testItResetsTheStoredPolicyByDefault(): void
    {
        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertNotSame([], $this->policyDeletes());
        $this->assertStringContainsString('Access policy reset', $tester->getDisplay());
    }

    public function testItKeepsTheStoredPolicyOnRequest(): void
    {
        $tester = $this->tester();
        $tester->execute(['--force' => true, '--keep-policy' => true]);

        $this->assertSame([], $this->policyDeletes());
        $this->assertStringContainsString('Access policy kept', $tester->getDisplay());
    }

    public function testItAbortsWhenTheOperatorDeclines(): void
    {
        $this->connection->expects($this->never())->method('executeStatement');

        $tester = $this->tester();
        $tester->setInputs(['no']);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing changed', $tester->getDisplay());
    }

    public function testItWarnsThatARestartUndoesTheReset(): void
    {
        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('SEED_DEMO_DATA=false', $tester->getDisplay());
    }

    /** @return list<string> */
    private function policyDeletes(): array
    {
        return array_values(array_filter(
            $this->prepared,
            static fn (string $sql): bool => str_contains($sql, ':registration')
        ));
    }

    /**
     * @param list<string> $tables
     */
    private function statementReturning(array $tables): Statement&MockObject
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn($tables);

        $statement = $this->createMock(Statement::class);
        $statement->method('executeQuery')->willReturn($result);

        return $statement;
    }

    private function tester(string $environment = 'dev'): CommandTester
    {
        $application = new Application();
        $application->addCommand(new SetupResetCommand($this->connection, $environment));

        return new CommandTester($application->find('app:setup:reset'));
    }
}
