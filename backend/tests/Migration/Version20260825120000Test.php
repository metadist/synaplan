<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260825120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the setup-backfill migration's real SQL against the test database.
 *
 * This is the most expensive thing in the whole feature to get wrong: if the
 * backfill misses, an upgraded production installation reopens its first-run
 * setup wizard, and whoever loads the URL first becomes its administrator. So
 * the three decisions are asserted here rather than described in a comment.
 *
 * Everything runs inside a transaction that is rolled back, and each case sets up
 * the exact population it needs — the PHPUnit database may or may not carry
 * fixtures, and this test must not depend on which.
 */
final class Version20260825120000Test extends KernelTestCase
{
    private const PROVIDER_KEY_GROUP = 'provider_keys';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
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

    /**
     * A genuine new installation runs the whole chain against an empty database.
     * Setting the flag here would make the wizard unreachable and leave the
     * install with no way to create an administrator.
     */
    public function testItLeavesTheWindowOpenOnAnEmptyDatabase(): void
    {
        $this->givenNoSetupFlag();
        $this->givenNoProviderKeys();
        $this->givenNoUsers();

        $this->runMigration();

        self::assertNull($this->fetchSetupFlag());
    }

    /** The normal upgrade: an installation with accounts is already set up. */
    public function testItClosesTheWindowWhenUsersExist(): void
    {
        $this->givenNoSetupFlag();
        $this->givenNoProviderKeys();
        $this->givenUser('ADMIN');

        $this->runMigration();

        self::assertSame('1', $this->fetchSetupFlag());
    }

    /**
     * A BUSERLEVEL='ANONYMOUS' row is created by the public email/WhatsApp
     * webhooks, so an instance that only ever ran as a channel bot has no
     * "real" account — and must still count as in use.
     */
    public function testAnAnonymousChannelUserAlsoClosesTheWindow(): void
    {
        $this->givenNoSetupFlag();
        $this->givenNoProviderKeys();
        $this->givenNoUsers();
        $this->givenUser('ANONYMOUS');
        self::assertSame(1, $this->userCount(), 'the anonymous row must be the only user');

        $this->runMigration();

        self::assertSame('1', $this->fetchSetupFlag());
    }

    /**
     * The edge case: an instance that ran long enough for an admin to store a
     * provider key, but whose user rows are gone. Provider keys are only ever
     * written at runtime, so their presence is proof of prior use.
     */
    public function testItClosesTheWindowForAKeyWithoutAnyUser(): void
    {
        $this->givenNoSetupFlag();
        $this->givenNoUsers();
        $this->givenProviderKey();

        $this->runMigration();

        self::assertSame('1', $this->fetchSetupFlag());
    }

    /**
     * BCONFIG has no unique index on (BOWNERID, BGROUP, BSETTING), so a second
     * run must not append a duplicate row — a duplicate would be harmless for
     * reads but would break the admin surface that expects one row per setting.
     */
    public function testItIsIdempotent(): void
    {
        $this->givenNoSetupFlag();
        $this->givenNoProviderKeys();
        $this->givenUser('ADMIN');

        $this->runMigration();
        $this->runMigration();

        self::assertSame(1, $this->setupFlagRowCount());
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

    /**
     * migrations/ is outside the composer autoload map — Doctrine discovers
     * these files by path at runtime, so the test has to load it itself.
     */
    private function loadMigration(): AbstractMigration
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20260825120000.php';

        return new Version20260825120000($this->connection, new NullLogger());
    }

    private function givenNoSetupFlag(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'SETUP' AND BSETTING = 'COMPLETED'"
        );
    }

    private function givenNoProviderKeys(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM BCONFIG WHERE BGROUP = :group',
            ['group' => self::PROVIDER_KEY_GROUP]
        );
    }

    private function givenProviderKey(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (0, :group, :setting, :value)',
            ['group' => self::PROVIDER_KEY_GROUP, 'setting' => 'openai', 'value' => 'encrypted-fixture']
        );
    }

    /**
     * Simulating the empty database means removing whatever users the harness
     * seeded. Safe only because the surrounding transaction is rolled back in
     * tearDown(); FK checks go off because several children of BUSER have no
     * ON DELETE CASCADE.
     */
    private function givenNoUsers(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $this->connection->executeStatement('DELETE FROM BUSER');
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Raw INSERT rather than the User entity: the migration reads through
     * `$this->connection`, and going through the ORM would risk landing the row on
     * a different connection than the one inside this test's transaction.
     */
    private function givenUser(string $level): void
    {
        $this->connection->executeStatement(
            'INSERT INTO BUSER (BCREATED, BMAIL, BPROVIDERID, BUSERLEVEL, BUSERDETAILS, BPAYMENTDETAILS)
             VALUES (:created, :mail, :provider, :level, :details, :details)',
            [
                'created' => date('YmdHis'),
                'mail' => 'migration-fixture@example.com',
                'provider' => 'local',
                'level' => $level,
                'details' => '{}',
            ]
        );
    }

    private function userCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM BUSER');
    }

    private function fetchSetupFlag(): ?string
    {
        $value = $this->connection->fetchOne(
            "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'SETUP' AND BSETTING = 'COMPLETED'"
        );

        return false === $value ? null : (string) $value;
    }

    private function setupFlagRowCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'SETUP' AND BSETTING = 'COMPLETED'"
        );
    }
}
