<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Run kernel (AH8a): one table for assistant, research and chat runs. The
 * live checkpoint, claim and events stay in Redis; this row keeps the run,
 * its outcome and its ledger for the retention window.
 *
 * Galera-safe: raw addSql only, IF NOT EXISTS, no Schema API, no foreign
 * keys. Nothing writes to the table until a later step starts runs on it.
 */
final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BRUNS for the run kernel (assistant, research and chat runs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS BRUNS (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BUUID CHAR(26) NOT NULL,
            BKIND VARCHAR(16) NOT NULL,
            BUSERID BIGINT NOT NULL,
            BSTARTEDBY BIGINT DEFAULT NULL,
            BAGENTID BIGINT DEFAULT NULL,
            BAGENTVERSIONID BIGINT DEFAULT NULL,
            BPARENTID BIGINT DEFAULT NULL,
            BCHATID BIGINT DEFAULT NULL,
            BORIGIN VARCHAR(32) NOT NULL,
            BSTATUS VARCHAR(24) NOT NULL,
            BNOTES MEDIUMTEXT DEFAULT NULL,
            BWAITREASON VARCHAR(16) DEFAULT NULL,
            BJOB TEXT NOT NULL,
            BOUTCOME TEXT DEFAULT NULL,
            BLEDGER JSON DEFAULT NULL,
            BSTEPS INT NOT NULL,
            BCOSTMICRO BIGINT NOT NULL,
            BCREATED INT NOT NULL,
            BSTARTED INT DEFAULT NULL,
            BFINISHED INT DEFAULT NULL,
            BIDEMPOTENCY VARCHAR(64) DEFAULT NULL,
            UNIQUE INDEX uniq_run_uuid (BUUID),
            INDEX idx_run_user_status (BUSERID, BSTATUS, BCREATED),
            INDEX idx_run_parent (BPARENTID),
            INDEX idx_run_agent (BAGENTID, BCREATED),
            PRIMARY KEY (BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BRUNS');
    }
}
