<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260930120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Legacy "Generated image: …" OUT rows become markers; media_prompt is filled
 * when missing. A second run is a no-op.
 */
final class Version20260930120000Test extends KernelTestCase
{
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

    public function testRewritesGeneratedImageWithoutMetaAndIsIdempotent(): void
    {
        $messageId = $this->insertOutMessage('Generated image: foo');

        $this->runUp();

        $row = $this->fetchMessage($messageId);
        self::assertSame('__IMAGE_GENERATED__', $row['BTEXT']);
        self::assertSame('foo', $this->fetchMediaPrompt($messageId));

        $this->runUp();

        $rowAgain = $this->fetchMessage($messageId);
        self::assertSame('__IMAGE_GENERATED__', $rowAgain['BTEXT']);
        self::assertSame('foo', $this->fetchMediaPrompt($messageId));
        self::assertSame(1, $this->countMediaPromptRows($messageId));
    }

    public function testPreservesExistingMediaPrompt(): void
    {
        $messageId = $this->insertOutMessage('Generated video: rewritten by provider');
        $this->insertMeta($messageId, 'media_prompt', 'original prompt');

        $this->runUp();

        self::assertSame('__VIDEO_GENERATED__', $this->fetchMessage($messageId)['BTEXT']);
        self::assertSame('original prompt', $this->fetchMediaPrompt($messageId));
        self::assertSame(1, $this->countMediaPromptRows($messageId));
    }

    public function testRewritesGeneratedAudio(): void
    {
        $messageId = $this->insertOutMessage('Generated audio: hello there');

        $this->runUp();

        self::assertSame('__AUDIO_GENERATED__', $this->fetchMessage($messageId)['BTEXT']);
        self::assertSame('hello there', $this->fetchMediaPrompt($messageId));
    }

    private function runUp(): void
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
        require_once dirname(__DIR__, 2).'/migrations/Version20260930120000.php';

        return new Version20260930120000($this->connection, new NullLogger());
    }

    private function insertOutMessage(string $text): int
    {
        $this->connection->executeStatement(
            'INSERT INTO BMESSAGES (
                BUSERID, BCHATID, BTRACKID, BPROVIDX, BUNIXTIMES, BDATETIME,
                BMESSTYPE, BFILE, BFILEPATH, BFILETYPE, BTOPIC, BLANG, BTEXT, BDIRECT, BSTATUS, BFILETEXT
            ) VALUES (
                1, NULL, 1, :provider, :ts, :dt,
                :type, 0, :path, :ftype, :topic, :lang, :text, :dir, :status, :filetext
            )',
            [
                'provider' => 'TEST',
                'ts' => time(),
                'dt' => date('YmdHis'),
                'type' => 'WEB',
                'path' => '',
                'ftype' => '',
                'topic' => 'PIC',
                'lang' => 'en',
                'text' => $text,
                'dir' => 'OUT',
                'status' => 'complete',
                'filetext' => '',
            ],
        );

        return (int) $this->connection->lastInsertId();
    }

    private function insertMeta(int $messageId, string $key, string $value): void
    {
        $this->connection->executeStatement(
            'INSERT INTO BMESSAGEMETA (BMESSAGEID, BMETAKEY, BMETAVALUE, BCREATED) VALUES (:id, :key, :value, :created)',
            [
                'id' => $messageId,
                'key' => $key,
                'value' => $value,
                'created' => time(),
            ],
        );
    }

    /**
     * @return array{BTEXT: string}
     */
    private function fetchMessage(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT BTEXT FROM BMESSAGES WHERE BID = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    private function fetchMediaPrompt(int $messageId): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT BMETAVALUE FROM BMESSAGEMETA WHERE BMESSAGEID = :id AND BMETAKEY = :key',
            ['id' => $messageId, 'key' => 'media_prompt'],
        );

        return false === $value ? null : (string) $value;
    }

    private function countMediaPromptRows(int $messageId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM BMESSAGEMETA WHERE BMESSAGEID = :id AND BMETAKEY = :key',
            ['id' => $messageId, 'key' => 'media_prompt'],
        );
    }
}
