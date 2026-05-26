<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add training examples to BPROMPTS and create BROUTING_FEEDBACKS table
 * for verified user routing corrections.
 */
final class Version20260526180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BTRAINING_EXAMPLES column to BPROMPTS; create BROUTING_FEEDBACKS table for routing feedback with AI verification status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPROMPTS ADD BTRAINING_EXAMPLES TEXT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE BROUTING_FEEDBACKS (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BUSER_ID BIGINT NOT NULL,
                BMESSAGE_ID BIGINT NOT NULL,
                BORIGINAL_TOPIC VARCHAR(64) NOT NULL,
                BSUGGESTED_TOPIC VARCHAR(64) NOT NULL,
                BSTATUS VARCHAR(16) NOT NULL DEFAULT 'pending',
                BVERIFICATION_REASON TEXT DEFAULT NULL,
                BCREATED_AT DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_feedback_user (BUSER_ID),
                INDEX idx_feedback_status (BSTATUS),
                INDEX idx_feedback_suggested (BSUGGESTED_TOPIC),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPROMPTS DROP COLUMN BTRAINING_EXAMPLES');
        $this->addSql('DROP TABLE BROUTING_FEEDBACKS');
    }
}
