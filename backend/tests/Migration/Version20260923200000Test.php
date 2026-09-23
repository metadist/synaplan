<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260923200000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Self-imported free models become visible in the pickers (#2110).
 *
 * Rows ModelImportApplier wrote before the fix carry zero prices and
 * BSHOWWHENFREE = 0, which isHiddenBecauseFree() hides from /config/models.
 * The migration flips exactly those rows to visible: imported (meta.import
 * provenance in BJSON) ollama / OpenAICompatible rows that are free and
 * currently hidden. Priced rows, non-imported rows, other services, and rows
 * that are already visible are left alone, and a second run changes nothing.
 */
final class Version20260923200000Test extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        self::requireMigration();
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

    public function testItShowsHiddenImportedFreeRows(): void
    {
        $ollama = $this->givenModel('ollama', $this->importJson('ollama'));
        $compatible = $this->givenModel('OpenAICompatible', $this->importJson('openai_compatible:acme'));

        $this->runMigrationUp();

        self::assertSame(1, $this->fetchShowWhenFree($ollama));
        self::assertSame(1, $this->fetchShowWhenFree($compatible));
    }

    public function testItLeavesPricedImportedRowsAlone(): void
    {
        $priceIn = $this->givenModel('ollama', $this->importJson('ollama'), 0.5, 0.0);
        $priceOut = $this->givenModel('ollama', $this->importJson('ollama'), 0.0, 1.5);

        $this->runMigrationUp();

        self::assertSame(0, $this->fetchShowWhenFree($priceIn), 'a priced import is never hidden, so there is nothing to repair');
        self::assertSame(0, $this->fetchShowWhenFree($priceOut), 'a priced import is never hidden, so there is nothing to repair');
    }

    public function testItLeavesNonImportedFreeRowsAlone(): void
    {
        $manual = $this->givenModel('ollama', '{}');
        $otherService = $this->givenModel('Groq', $this->importJson('ollama'));
        $alreadyVisible = $this->givenModel('ollama', $this->importJson('ollama'), 0.0, 0.0, 1);

        $this->runMigrationUp();

        self::assertSame(0, $this->fetchShowWhenFree($manual), 'a free row without import provenance stays hidden');
        self::assertSame(0, $this->fetchShowWhenFree($otherService), 'import provenance on another service stays hidden');
        self::assertSame(1, $this->fetchShowWhenFree($alreadyVisible));
    }

    public function testItIsIdempotent(): void
    {
        $id = $this->givenModel('ollama', $this->importJson('ollama'));

        $this->runMigrationUp();
        self::assertSame(1, $this->fetchShowWhenFree($id));

        $this->runMigrationUp();
        self::assertSame(1, $this->fetchShowWhenFree($id));
    }

    public function testDownIsANoop(): void
    {
        $hidden = $this->givenModel('ollama', $this->importJson('ollama'));
        $visible = $this->givenModel('ollama', $this->importJson('ollama'), 0.0, 0.0, 1);

        $this->runMigrationDown();

        self::assertSame(0, $this->fetchShowWhenFree($hidden));
        self::assertSame(1, $this->fetchShowWhenFree($visible));
    }

    private function runMigrationUp(): void
    {
        $migration = $this->loadMigration();
        $migration->up(new Schema());
        $this->executeCollectedSql($migration);
    }

    private function runMigrationDown(): void
    {
        $migration = $this->loadMigration();
        $migration->down(new Schema());
        $this->executeCollectedSql($migration);
    }

    private function executeCollectedSql(AbstractMigration $migration): void
    {
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
        return new Version20260923200000($this->connection, new NullLogger());
    }

    private static function requireMigration(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20260923200000.php';
    }

    private function importJson(string $source): string
    {
        $json = json_encode(['meta' => ['import' => ['source' => $source, 'importedAt' => 1, 'lastSeenAt' => 1]]]);
        self::assertIsString($json);

        return $json;
    }

    private function givenModel(string $service, string $json, float $priceIn = 0.0, float $priceOut = 0.0, int $showWhenFree = 0): int
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BMODELS (BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:service, 'Migration Test Model', 'chat', 1, 1, :providerId, :priceIn, 'per1M', :priceOut, 'per1M', 7, 0.5, 0, :showWhenFree, :json)
            SQL,
            [
                'service' => $service,
                'providerId' => 'itest-2110-migration-'.uniqid(),
                'priceIn' => $priceIn,
                'priceOut' => $priceOut,
                'showWhenFree' => $showWhenFree,
                'json' => $json,
            ]
        );

        return (int) $this->connection->lastInsertId();
    }

    private function fetchShowWhenFree(int $id): int
    {
        return (int) $this->connection->fetchOne('SELECT BSHOWWHENFREE FROM BMODELS WHERE BID = :id', ['id' => $id]);
    }
}
