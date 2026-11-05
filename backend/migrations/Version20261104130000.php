<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI Act provenance and the optional literacy record.
 * Raw SQL: this cluster rejects the schema comparator.
 */
final class Version20261104130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add generated-file provenance columns, channel AI notices, and literacy confirmations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BGENERATED TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BGENMODEL VARCHAR(96) NULL');
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BCREDENTIALS VARCHAR(16) NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCHANNELNOTICE (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BCHANNEL VARCHAR(16) NOT NULL,
              BBINDINGID BIGINT NOT NULL DEFAULT 0,
              BTEXT VARCHAR(500) NOT NULL,
              UNIQUE INDEX uq_channel_notice (BOWNERID, BCHANNEL, BBINDINGID),
              PRIMARY KEY (BID)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BAI_LITERACY_ACK (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUSERID BIGINT NOT NULL,
              BVERSION VARCHAR(32) NOT NULL,
              BCONFIRMED BIGINT DEFAULT NULL,
              BREMINDAT BIGINT DEFAULT NULL,
              UNIQUE INDEX uq_ai_literacy_ack (BUSERID, BVERSION),
              PRIMARY KEY (BID)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BAI_LITERACY_ACK');
        $this->addSql('DROP TABLE IF EXISTS BCHANNELNOTICE');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BCREDENTIALS');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BGENMODEL');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BGENERATED');
    }
}
