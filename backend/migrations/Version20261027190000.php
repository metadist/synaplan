<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Watched folders and files. Raw SQL: this cluster rejects the schema comparator.
 * Items are dropped before watches; no ON DELETE CASCADE is assumed.
 */
final class Version20261027190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cloud watches, their item ledger, and BFILES.BEXPIRESAT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BFILES ADD COLUMN IF NOT EXISTS BEXPIRESAT BIGINT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCLOUDWATCHES (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BIDENTITYID BIGINT DEFAULT NULL,
              BCONNECTIONID BIGINT DEFAULT NULL,
              BTARGETID VARCHAR(255) NOT NULL,
              BTARGETKIND VARCHAR(16) NOT NULL,
              BTARGETPATH VARCHAR(255) NOT NULL DEFAULT '',
              BRECURSIVE TINYINT(1) NOT NULL DEFAULT 0,
              BEVENTS JSON DEFAULT NULL,
              BFILTER JSON DEFAULT NULL,
              BACTION VARCHAR(16) NOT NULL,
              BPRESET VARCHAR(32) DEFAULT NULL,
              BFILESET VARCHAR(16) NOT NULL DEFAULT 'new_file',
              BWINDOWSEC INT NOT NULL DEFAULT 600,
              BMAXFILES INT NOT NULL DEFAULT 50,
              BGROUPKEY VARCHAR(64) DEFAULT NULL,
              BTASKID BIGINT DEFAULT NULL,
              BSTATE VARCHAR(16) NOT NULL,
              BREASON VARCHAR(32) DEFAULT NULL,
              BCOUNTS JSON DEFAULT NULL,
              BLASTEVENTAT BIGINT DEFAULT NULL,
              BLASTRUNAT BIGINT DEFAULT NULL,
              BLASTSTATUS VARCHAR(32) DEFAULT NULL,
              BCREATEDIN VARCHAR(16) NOT NULL DEFAULT 'cloud',
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              INDEX idx_cloudwatch_owner (BOWNERID, BSTATE),
              INDEX idx_cloudwatch_identity (BIDENTITYID),
              INDEX idx_cloudwatch_target (BTARGETID),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCLOUDWATCHITEMS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BWATCHID BIGINT NOT NULL,
              BSOURCEID VARCHAR(255) NOT NULL,
              BVERSION VARCHAR(255) NOT NULL,
              BSTATE VARCHAR(16) NOT NULL,
              BREASON VARCHAR(32) DEFAULT NULL,
              BFILEID BIGINT DEFAULT NULL,
              BRUNID BIGINT DEFAULT NULL,
              BDELIVERYID BIGINT DEFAULT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              UNIQUE INDEX uniq_cloudwatch_item (BWATCHID, BSOURCEID, BVERSION),
              INDEX idx_cloudwatch_item_state (BWATCHID, BSTATE, BUPDATED),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BCLOUDWATCHITEMS');
        $this->addSql('DROP TABLE IF EXISTS BCLOUDWATCHES');
        $this->addSql('ALTER TABLE BFILES DROP COLUMN IF EXISTS BEXPIRESAT');
    }
}
