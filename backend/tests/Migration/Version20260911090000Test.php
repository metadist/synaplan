<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260911090000;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the wave-flag switch-on against the test database.
 *
 * The migration overwrites operator data on purpose (every recognised OFF
 * spelling becomes ON), so the things worth pinning down are its edges: which
 * spellings count as OFF, that unrecognised values, ON values, per-user rows
 * and the deliberately excluded flags survive, that nothing is created, and
 * that a second run is a no-op.
 *
 * Everything runs inside a transaction that is rolled back; each case builds
 * the rows it needs because the PHPUnit database may or may not carry fixtures.
 */
final class Version20260911090000Test extends KernelTestCase
{
    private const GLOBAL_OWNER = 0;
    private const SOME_USER = 4711;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        self::requireMigration();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        foreach (Version20260911090000::FLAGS as [$group, $setting]) {
            $this->connection->executeStatement(
                'DELETE FROM BCONFIG WHERE BGROUP = :g AND BSETTING = :s',
                ['g' => $group, 's' => $setting]
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideOffSpellings(): iterable
    {
        yield 'zero' => ['0'];
        yield 'false' => ['false'];
        yield 'FALSE' => ['FALSE'];
        yield 'off' => ['off'];
        yield 'no' => ['no'];
        yield 'empty' => [''];
        yield 'padded zero' => [' 0 '];
    }

    #[DataProvider('provideOffSpellings')]
    public function testItTurnsEveryRecognisedOffSpellingOn(string $stored): void
    {
        foreach (Version20260911090000::FLAGS as [$group, $setting]) {
            $this->givenGlobalRow($group, $setting, $stored);
        }

        $this->runMigration();

        foreach (Version20260911090000::FLAGS as [$group, $setting]) {
            self::assertSame('1', $this->fetchRow(self::GLOBAL_OWNER, $group, $setting), "$group.$setting");
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideValuesLeftAlone(): iterable
    {
        yield 'one' => ['1'];
        yield 'true' => ['true'];
        yield 'on' => ['on'];
        yield 'unrecognised' => ['maybe'];
    }

    #[DataProvider('provideValuesLeftAlone')]
    public function testItLeavesOnAndUnrecognisedValuesAlone(string $stored): void
    {
        $this->givenGlobalRow('AGENTS', 'ENABLED', $stored);

        $this->runMigration();

        self::assertSame($stored, $this->fetchRow(self::GLOBAL_OWNER, 'AGENTS', 'ENABLED'));
    }

    /** Only the global row is the shipped default; a user row is always a choice. */
    public function testItLeavesPerUserRowsAlone(): void
    {
        $this->givenGlobalRow('IAM', 'SHARING_ENABLED', '0');
        $this->givenRow(self::SOME_USER, 'IAM', 'SHARING_ENABLED', '0');

        $this->runMigration();

        self::assertSame('1', $this->fetchRow(self::GLOBAL_OWNER, 'IAM', 'SHARING_ENABLED'));
        self::assertSame('0', $this->fetchRow(self::SOME_USER, 'IAM', 'SHARING_ENABLED'));
    }

    /** Module gates ship default-off and the registry kill switch has seeded ON since Wave 4. */
    public function testItLeavesTheExcludedFlagsAlone(): void
    {
        $this->givenGlobalRow('MODULES', 'GATE_TIKA', '0');
        $this->givenGlobalRow('TOOLS', 'REGISTRY_ENABLED', '0');
        $this->givenGlobalRow('DOCUMENT_TOOLS', 'ALLOW_UPLOAD_EDIT', '0');

        $this->runMigration();

        self::assertSame('0', $this->fetchRow(self::GLOBAL_OWNER, 'MODULES', 'GATE_TIKA'));
        self::assertSame('0', $this->fetchRow(self::GLOBAL_OWNER, 'TOOLS', 'REGISTRY_ENABLED'));
        self::assertSame('0', $this->fetchRow(self::GLOBAL_OWNER, 'DOCUMENT_TOOLS', 'ALLOW_UPLOAD_EDIT'));
    }

    /** Installs without a row keep relying on the seeder; the migration never inserts. */
    public function testItCreatesNoRows(): void
    {
        $this->runMigration();

        foreach (Version20260911090000::FLAGS as [$group, $setting]) {
            self::assertNull($this->fetchRow(self::GLOBAL_OWNER, $group, $setting), "$group.$setting");
        }
    }

    public function testItIsIdempotent(): void
    {
        $this->givenGlobalRow('WORKFLOWS', 'BUILDER_ENABLED', '0');
        $this->givenGlobalRow('DESKTOP_AGENT', 'ENABLED', 'true');

        $this->runMigration();
        $this->runMigration();

        self::assertSame('1', $this->fetchRow(self::GLOBAL_OWNER, 'WORKFLOWS', 'BUILDER_ENABLED'));
        self::assertSame('true', $this->fetchRow(self::GLOBAL_OWNER, 'DESKTOP_AGENT', 'ENABLED'));
    }

    private function runMigration(): void
    {
        $migration = $this->loadMigration();
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement(
                $query->getStatement(),
                $query->getParameters(),
                $query->getTypes()
            );
        }
    }

    private function loadMigration(): AbstractMigration
    {
        return new Version20260911090000($this->connection, new NullLogger());
    }

    /**
     * migrations/ is outside the composer autoload map — Doctrine discovers
     * these files by path at runtime, so the test has to load it itself.
     */
    private static function requireMigration(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20260911090000.php';
    }

    private function givenGlobalRow(string $group, string $setting, string $value): void
    {
        $this->givenRow(self::GLOBAL_OWNER, $group, $setting, $value);
    }

    /** Upsert: BCONFIG is uniquely indexed on (BOWNERID, BGROUP, BSETTING). */
    private function givenRow(int $ownerId, string $group, string $setting, string $value): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
                VALUES (:owner, :g, :s, :value)
                ON DUPLICATE KEY UPDATE BVALUE = VALUES(BVALUE)
            SQL,
            ['owner' => $ownerId, 'g' => $group, 's' => $setting, 'value' => $value]
        );
    }

    private function fetchRow(int $ownerId, string $group, string $setting): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT BVALUE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = :g AND BSETTING = :s',
            ['owner' => $ownerId, 'g' => $group, 's' => $setting]
        );

        return false === $value ? null : (string) $value;
    }
}
