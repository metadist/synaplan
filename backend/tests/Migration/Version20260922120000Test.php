<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Seed\IamConfigSeeder;
use App\Service\Iam\IamConfig;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260922120000;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Open self-registration installs lose the instance-wide everyone audience.
 * Invite-only and SSO-only installs keep the stored value. The seeder fills
 * a missing row the same way and never overwrites one that exists (#2096).
 */
final class Version20260922120000Test extends KernelTestCase
{
    private const ENV = 'REGISTRATION_ENABLED';

    private Connection $connection;

    private ?string $previousEnv = null;

    private bool $hadEnv = false;

    protected function setUp(): void
    {
        self::bootKernel();
        self::requireMigration();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->hadEnv = \array_key_exists(self::ENV, $_ENV);
        $this->previousEnv = $this->hadEnv ? (string) $_ENV[self::ENV] : null;
        unset($_ENV[self::ENV]);
    }

    protected function tearDown(): void
    {
        if ($this->hadEnv) {
            $_ENV[self::ENV] = $this->previousEnv;
        } else {
            unset($_ENV[self::ENV]);
        }
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOpenRegistrationDisablesTheStoredAudience(): void
    {
        $this->givenRegistration('1');
        $this->givenEveryone(IamConfig::EVERYONE_SHARES_ANY_OWNER);

        $this->runMigration();

        self::assertSame(IamConfig::EVERYONE_SHARES_DISABLED, $this->fetchEveryone(0));
    }

    public function testOpenRegistrationAlsoClosesAnAdminsOnlyRow(): void
    {
        $this->givenRegistration('true');
        $this->givenEveryone(IamConfig::EVERYONE_SHARES_ADMINS_ONLY);

        $this->runMigration();

        self::assertSame(IamConfig::EVERYONE_SHARES_DISABLED, $this->fetchEveryone(0));
    }

    /** No registration row means the built-in default: anyone can sign up. */
    public function testMissingRegistrationRowCountsAsOpen(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'ACCESS' AND BSETTING = 'REGISTRATION_ENABLED'"
        );
        $this->givenEveryone(IamConfig::EVERYONE_SHARES_ANY_OWNER);

        $this->runMigration();

        self::assertSame(IamConfig::EVERYONE_SHARES_DISABLED, $this->fetchEveryone(0));
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideClosedRegistration(): iterable
    {
        yield 'zero' => ['0'];
        yield 'false' => ['false'];
        yield 'off' => ['off'];
        yield 'no' => ['no'];
    }

    #[DataProvider('provideClosedRegistration')]
    public function testInviteOnlyKeepsTheStoredValue(string $stored): void
    {
        $this->givenRegistration($stored);
        $this->givenEveryone(IamConfig::EVERYONE_SHARES_ANY_OWNER);

        $this->runMigration();

        self::assertSame(IamConfig::EVERYONE_SHARES_ANY_OWNER, $this->fetchEveryone(0));
    }

    public function testEnvPinWinsOverTheStoredRegistrationFlag(): void
    {
        $this->givenRegistration('1');
        $this->givenEveryone(IamConfig::EVERYONE_SHARES_ANY_OWNER);
        $_ENV[self::ENV] = 'false';

        $this->runMigration();

        self::assertSame(IamConfig::EVERYONE_SHARES_ANY_OWNER, $this->fetchEveryone(0));

        $_ENV[self::ENV] = 'true';
        $this->givenRegistration('0');
        $this->givenEveryone(IamConfig::EVERYONE_SHARES_ADMINS_ONLY);

        $this->runMigration();

        self::assertSame(IamConfig::EVERYONE_SHARES_DISABLED, $this->fetchEveryone(0));
    }

    public function testItLeavesPerUserRowsAlone(): void
    {
        $this->givenRegistration('1');
        $this->givenEveryone(IamConfig::EVERYONE_SHARES_ANY_OWNER);
        $this->givenRow(4711, IamConfig::CONFIG_GROUP, IamConfig::KEY_EVERYONE_SHARES, IamConfig::EVERYONE_SHARES_ANY_OWNER);

        $this->runMigration();

        self::assertSame(IamConfig::EVERYONE_SHARES_DISABLED, $this->fetchEveryone(0));
        self::assertSame(IamConfig::EVERYONE_SHARES_ANY_OWNER, $this->fetchEveryone(4711));
    }

    public function testItCreatesNoRowAndIsIdempotent(): void
    {
        $this->givenRegistration('1');
        $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BGROUP = 'IAM' AND BSETTING = 'EVERYONE_SHARES'"
        );

        $this->runMigration();

        self::assertNull($this->fetchEveryone(0));

        $this->givenEveryone(IamConfig::EVERYONE_SHARES_DISABLED);
        $this->runMigration();
        $this->runMigration();

        self::assertSame(IamConfig::EVERYONE_SHARES_DISABLED, $this->fetchEveryone(0));
    }

    public function testSeederFillsDisabledWhenAnyoneCanSignUp(): void
    {
        $this->givenRegistration('1');
        $this->deleteEveryoneRow();
        $this->clearIdentityMap();

        self::getContainer()->get(IamConfigSeeder::class)->seed();

        self::assertSame(IamConfig::EVERYONE_SHARES_DISABLED, $this->fetchEveryone(0));
    }

    public function testSeederFillsAnyOwnerWhenRegistrationIsClosed(): void
    {
        $this->givenRegistration('0');
        $this->deleteEveryoneRow();
        $this->clearIdentityMap();

        self::getContainer()->get(IamConfigSeeder::class)->seed();

        self::assertSame(IamConfig::EVERYONE_SHARES_ANY_OWNER, $this->fetchEveryone(0));
    }

    public function testSeederDoesNotOverwriteAnExistingRow(): void
    {
        $this->givenRegistration('1');
        $this->givenEveryone(IamConfig::EVERYONE_SHARES_ANY_OWNER);
        $this->clearIdentityMap();

        self::getContainer()->get(IamConfigSeeder::class)->seed();

        self::assertSame(IamConfig::EVERYONE_SHARES_ANY_OWNER, $this->fetchEveryone(0));
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
        return new Version20260922120000($this->connection, new NullLogger());
    }

    private static function requireMigration(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20260922120000.php';
    }

    private function givenRegistration(string $value): void
    {
        $this->givenRow(0, 'ACCESS', 'REGISTRATION_ENABLED', $value);
    }

    private function givenEveryone(string $value): void
    {
        $this->givenRow(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_EVERYONE_SHARES, $value);
    }

    private function deleteEveryoneRow(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'IAM' AND BSETTING = 'EVERYONE_SHARES'"
        );
    }

    private function clearIdentityMap(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

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

    private function fetchEveryone(int $ownerId): ?string
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(c.BVALUE, '__missing__')
                FROM (SELECT 1 AS dummy) d
                LEFT JOIN BCONFIG c
                  ON c.BOWNERID = :owner AND c.BGROUP = :g AND c.BSETTING = :s
            SQL,
            ['owner' => $ownerId, 'g' => IamConfig::CONFIG_GROUP, 's' => IamConfig::KEY_EVERYONE_SHARES]
        );

        return !\is_string($value) || '__missing__' === $value ? null : $value;
    }
}
