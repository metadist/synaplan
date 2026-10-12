<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Seed\DefaultModelConfigSeeder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The global image defaults move from Nano Banana 2 to Flare only while they
 * still hold that row and Flare is active (#2407). User bindings stay.
 */
final class DefaultModelConfigImageDefaultTest extends KernelTestCase
{
    private const FLARE = 348;
    private const NANO = 371;
    private const OTHER = 349;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->givenModel(self::FLARE, 'gpt-image-2.5-flare', active: true);
        $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BSETTING IN ('TEXT2PIC', 'PIC2PIC')"
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItRepointsTheGlobalNanoBananaDefaults(): void
    {
        $this->givenBinding(0, 'PIC2PIC', self::NANO);
        $this->givenBinding(0, 'TEXT2PIC', self::NANO);

        $this->repoint();

        self::assertSame((string) self::FLARE, $this->fetch(0, 'PIC2PIC'));
        self::assertSame((string) self::FLARE, $this->fetch(0, 'TEXT2PIC'));
    }

    public function testItLeavesAUserBindingAlone(): void
    {
        $this->givenBinding(0, 'PIC2PIC', self::NANO);
        $this->givenBinding(7, 'PIC2PIC', self::NANO);

        $this->repoint();

        self::assertSame((string) self::FLARE, $this->fetch(0, 'PIC2PIC'));
        self::assertSame((string) self::NANO, $this->fetch(7, 'PIC2PIC'));
    }

    public function testItLeavesADifferentGlobalModelAlone(): void
    {
        $this->givenBinding(0, 'PIC2PIC', self::OTHER);

        $this->repoint();

        self::assertSame((string) self::OTHER, $this->fetch(0, 'PIC2PIC'));
    }

    public function testItSkipsWhenFlareIsInactive(): void
    {
        $this->givenModel(self::FLARE, 'gpt-image-2.5-flare', active: false);
        $this->givenBinding(0, 'PIC2PIC', self::NANO);

        $this->repoint();

        self::assertSame((string) self::NANO, $this->fetch(0, 'PIC2PIC'));
    }

    private function repoint(): void
    {
        (new DefaultModelConfigSeeder($this->connection, 'prod'))->repointGlobalNanoBananaImageDefaults();
    }

    private function givenModel(int $id, string $providerId, bool $active): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, 'OpenAI', 'GPT Image 2.5 Flare', 'text2pic', 1, :active, :providerId, 0, 'perImage', 0.01317, 'perImage', 10, 1, 0, 0, '{}')
                ON DUPLICATE KEY UPDATE
                    BSERVICE = 'OpenAI', BTAG = 'text2pic',
                    BPROVID = VALUES(BPROVID), BACTIVE = VALUES(BACTIVE)
            SQL,
            ['id' => $id, 'active' => $active ? 1 : 0, 'providerId' => $providerId]
        );
    }

    private function givenBinding(int $ownerId, string $setting, int $modelId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
                VALUES (:owner, 'DEFAULTMODEL', :setting, :value)
                ON DUPLICATE KEY UPDATE BVALUE = VALUES(BVALUE)
            SQL,
            ['owner' => $ownerId, 'setting' => $setting, 'value' => (string) $modelId]
        );
    }

    private function fetch(int $ownerId, string $setting): ?string
    {
        $value = $this->connection->fetchOne(
            "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = 'DEFAULTMODEL' AND BSETTING = :setting",
            ['owner' => $ownerId, 'setting' => $setting]
        );

        return false === $value ? null : (string) $value;
    }
}
