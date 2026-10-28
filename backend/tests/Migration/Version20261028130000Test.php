<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261028130000;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The web-search ceiling moves from the old seed of 2 to 4. A value an
 * administrator chose stays; a missing row is the seeder's business.
 */
final class Version20261028130000Test extends KernelTestCase
{
    private const GROUP = 'MESSAGES_GATEWAY';
    private const SETTING = 'WEB_SEARCH_MAX_ROUNDS';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        require_once \dirname(__DIR__, 2).'/migrations/Version20261028130000.php';
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheOldSeedBecomesFour(): void
    {
        $this->givenRow('2');

        $this->runMigration();

        self::assertSame('4', $this->fetchValue());
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideChosenValues(): iterable
    {
        yield 'one' => ['1'];
        yield 'three' => ['3'];
        yield 'six' => ['6'];
    }

    #[DataProvider('provideChosenValues')]
    public function testAValueAnAdministratorChoseStays(string $stored): void
    {
        $this->givenRow($stored);

        $this->runMigration();

        self::assertSame($stored, $this->fetchValue());
    }

    public function testAMissingRowIsNotInvented(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = :g AND BSETTING = :s',
            ['g' => self::GROUP, 's' => self::SETTING],
        );

        $this->runMigration();

        self::assertNull($this->fetchValue());
    }

    private function runMigration(): void
    {
        $migration = new Version20261028130000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function givenRow(string $value): void
    {
        $this->connection->executeStatement(
            'INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (0, :g, :s, :value) ON DUPLICATE KEY UPDATE BVALUE = VALUES(BVALUE)',
            ['g' => self::GROUP, 's' => self::SETTING, 'value' => $value],
        );
    }

    private function fetchValue(): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT BVALUE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = :g AND BSETTING = :s',
            ['g' => self::GROUP, 's' => self::SETTING],
        );

        return false === $value ? null : (string) $value;
    }
}
