<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260923190000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the Google image-model migration's real SQL against the test database.
 *
 * Image defaults, prompt overrides and widget overrides all store a BID. The
 * migration has to move every one of them off the shut-down preview ids, and
 * a second run must not clobber an operator flag on the new rows.
 */
final class Version20260923190000Test extends KernelTestCase
{
    private const PREVIEW_BANANA_BID = 190;
    private const PREVIEW_PRO_BID = 228;
    private const STABLE_BANANA_BID = 371;
    private const STABLE_PRO_BID = 372;

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

    public function testItRepointsEveryPlaceAPreviewImageIdCanHide(): void
    {
        $this->givenPreviewRowsAreLive();
        $this->deleteStableRows();

        $ownerId = $this->anyUserId();
        $this->givenDefaultModelBinding($ownerId, 'TEXT2PIC', self::PREVIEW_BANANA_BID);
        $this->givenDefaultModelBinding($ownerId, 'PIC2PIC', self::PREVIEW_PRO_BID);
        $promptId = $this->givenPromptWithModelOverride($ownerId, self::PREVIEW_BANANA_BID);
        $widgetId = $this->givenWidgetWithModelOverride($ownerId, self::PREVIEW_PRO_BID);

        $this->runUp();

        self::assertSame((string) self::STABLE_BANANA_BID, $this->fetchDefaultModelBinding($ownerId, 'TEXT2PIC'));
        self::assertSame((string) self::STABLE_PRO_BID, $this->fetchDefaultModelBinding($ownerId, 'PIC2PIC'));
        self::assertSame((string) self::STABLE_BANANA_BID, $this->fetchPromptModelOverride($promptId));
        self::assertSame(self::STABLE_PRO_BID, $this->fetchWidgetModelOverride($widgetId));
        self::assertSame(0, $this->fetchModelActive(self::PREVIEW_BANANA_BID));
        self::assertSame(0, $this->fetchModelActive(self::PREVIEW_PRO_BID));
        self::assertSame(self::STABLE_BANANA_BID, $this->fetchSuccessor(self::PREVIEW_BANANA_BID));
        self::assertSame(self::STABLE_PRO_BID, $this->fetchSuccessor(self::PREVIEW_PRO_BID));
        self::assertSame(1, $this->fetchModelActive(self::STABLE_BANANA_BID));
        self::assertSame(1, $this->fetchModelActive(self::STABLE_PRO_BID));
    }

    public function testASecondRunKeepsAnOperatorFlagAndStaysRepointed(): void
    {
        $this->givenPreviewRowsAreLive();
        $this->deleteStableRows();

        $ownerId = $this->anyUserId();
        $widgetId = $this->givenWidgetWithModelOverride($ownerId, self::PREVIEW_BANANA_BID);

        $this->runUp();
        $this->connection->executeStatement(
            'UPDATE BMODELS SET BSELECTABLE = 0 WHERE BID = :id',
            ['id' => self::STABLE_BANANA_BID],
        );
        $this->runUp();

        self::assertSame(self::STABLE_BANANA_BID, $this->fetchWidgetModelOverride($widgetId));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT BSELECTABLE FROM BMODELS WHERE BID = :id',
            ['id' => self::STABLE_BANANA_BID],
        ));
        self::assertSame(0, $this->fetchModelActive(self::PREVIEW_BANANA_BID));
    }

    public function testItLeavesUnrelatedWidgetConfigsAlone(): void
    {
        $this->givenPreviewRowsAreLive();
        $this->deleteStableRows();

        $ownerId = $this->anyUserId();
        $widgetId = $this->givenWidgetWithModelOverride($ownerId, 1);

        $this->runUp();

        $config = json_decode($this->fetchWidgetConfig($widgetId), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $config['aiModelId']);
        self::assertSame('#ff0000', $config['primaryColor']);
    }

    private function runUp(): void
    {
        $this->loadMigration()->up(new Schema());
    }

    private function loadMigration(): AbstractMigration
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20260923190000.php';

        return new Version20260923190000($this->connection, new NullLogger());
    }

    private function givenPreviewRowsAreLive(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, 'Google', 'Nano Banana 2 preview', 'text2pic', 1, 1, 'gemini-3.1-flash-image-preview', 0, 'perImage', 0.067, 'perImage', 10, 1, 0, 0, '{}')
                ON DUPLICATE KEY UPDATE BACTIVE = 1, BSELECTABLE = 1, BPROVID = 'gemini-3.1-flash-image-preview', BRETIREDON = NULL, BSUCCESSORID = NULL
                SQL,
            ['id' => self::PREVIEW_BANANA_BID],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, 'Google', 'Nano Banana Pro preview', 'text2pic', 1, 1, 'nano-banana-pro-preview', 0, 'perImage', 0.08, 'perImage', 10, 1, 0, 0, '{}')
                ON DUPLICATE KEY UPDATE BACTIVE = 1, BSELECTABLE = 1, BPROVID = 'nano-banana-pro-preview', BRETIREDON = NULL, BSUCCESSORID = NULL
                SQL,
            ['id' => self::PREVIEW_PRO_BID],
        );
    }

    private function deleteStableRows(): void
    {
        $this->connection->executeStatement('DELETE FROM BMODELS WHERE BID IN (371, 372)');
    }

    private function anyUserId(): int
    {
        $userId = $this->connection->fetchOne('SELECT BID FROM BUSER ORDER BY BID ASC LIMIT 1');
        self::assertNotFalse($userId, 'test database has no user to attach fixtures to');

        return (int) $userId;
    }

    private function givenDefaultModelBinding(int $ownerId, string $setting, int $modelId): void
    {
        $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = 'DEFAULTMODEL' AND BSETTING = :setting",
            ['owner' => $ownerId, 'setting' => $setting],
        );
        $this->connection->executeStatement(
            "INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (:owner, 'DEFAULTMODEL', :setting, :value)",
            ['owner' => $ownerId, 'setting' => $setting, 'value' => (string) $modelId],
        );
    }

    private function givenPromptWithModelOverride(int $ownerId, int $modelId): int
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BPROMPTS (BOWNERID, BLANG, BTOPIC, BSHORTDESC, BPROMPT)
                VALUES (:owner, 'en', 'tools:migration_fixture', 'fixture', 'fixture')
                SQL,
            ['owner' => $ownerId],
        );
        $promptId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BPROMPTMETA (BPROMPTID, BMETAKEY, BMETAVALUE, BCREATED)
                VALUES (:prompt, 'aiModel', :value, :created)
                SQL,
            ['prompt' => $promptId, 'value' => (string) $modelId, 'created' => time()],
        );

        return $promptId;
    }

    private function givenWidgetWithModelOverride(int $ownerId, int $modelId): string
    {
        $widgetId = 'wdg_migration_fixture_'.bin2hex(random_bytes(6));
        $config = json_encode(
            ['primaryColor' => '#ff0000', 'aiModelId' => $modelId],
            \JSON_THROW_ON_ERROR,
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BWIDGETS (BOWNERID, BWIDGETID, BTASKPROMPT, BNAME, BSTATUS, BCONFIG, BALLOWED_DOMAINS, BCREATED, BUPDATED)
                VALUES (:owner, :widgetId, 'general', 'fixture', 'active', :config, '[]', :now, :now)
                SQL,
            ['owner' => $ownerId, 'widgetId' => $widgetId, 'config' => $config, 'now' => time()],
        );

        return $widgetId;
    }

    private function fetchDefaultModelBinding(int $ownerId, string $setting): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = 'DEFAULTMODEL' AND BSETTING = :setting",
            ['owner' => $ownerId, 'setting' => $setting],
        );
    }

    private function fetchPromptModelOverride(int $promptId): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT BMETAVALUE FROM BPROMPTMETA WHERE BPROMPTID = :prompt AND BMETAKEY = 'aiModel'",
            ['prompt' => $promptId],
        );
    }

    private function fetchWidgetModelOverride(string $widgetId): int
    {
        $config = json_decode($this->fetchWidgetConfig($widgetId), true, 512, \JSON_THROW_ON_ERROR);

        return (int) $config['aiModelId'];
    }

    private function fetchWidgetConfig(string $widgetId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT BCONFIG FROM BWIDGETS WHERE BWIDGETID = :widgetId',
            ['widgetId' => $widgetId],
        );
    }

    private function fetchModelActive(int $modelId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT BACTIVE FROM BMODELS WHERE BID = :id',
            ['id' => $modelId],
        );
    }

    private function fetchSuccessor(int $modelId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT BSUCCESSORID FROM BMODELS WHERE BID = :id',
            ['id' => $modelId],
        );
    }
}
