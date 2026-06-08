<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove the embedding-based routing experiment from BPROMPTS.
 *
 * The vector-similarity routing prototype (BKEYWORDS for embedding synonyms,
 * BENABLED as a routing-pool soft-disable, plus the granular routing topics)
 * was retired in favour of the AI sorter as the single classifier and the
 * multi-task plan (DAG) router. This migration drops the now-unused columns
 * and deletes the leftover granular system topic rows so the routing pool
 * only contains the canonical topics.
 */
final class Version20260608000000 extends AbstractMigration
{
    private const GRANULAR_TOPICS = [
        'general-chat',
        'coding',
        'image-generation',
        'video-generation',
        'audio-generation',
    ];

    public function getDescription(): string
    {
        return 'Drop BPROMPTS.BKEYWORDS and BPROMPTS.BENABLED and remove leftover granular system topics';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Remove the leftover granular system topic rows (ownerId=0) so the
        // canonical-only routing pool is restored. User-created prompts are
        // never touched.
        $placeholders = implode(', ', array_fill(0, count(self::GRANULAR_TOPICS), '?'));
        $this->addSql(
            sprintf('DELETE FROM BPROMPTS WHERE BOWNERID = 0 AND BTOPIC IN (%s)', $placeholders),
            self::GRANULAR_TOPICS,
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE BPROMPTS
                DROP COLUMN BKEYWORDS,
                DROP COLUMN BENABLED
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Re-create the columns so the schema rolls back cleanly. The deleted
        // granular topic rows are not restored — they were a retired routing
        // experiment and are re-creatable only via an old seed.
        $this->addSql(<<<'SQL'
            ALTER TABLE BPROMPTS
                ADD COLUMN BKEYWORDS LONGTEXT DEFAULT NULL AFTER BSELECTION_RULES,
                ADD COLUMN BENABLED TINYINT(1) NOT NULL DEFAULT 1 AFTER BKEYWORDS
        SQL);
    }
}
