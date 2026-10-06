<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Long-term memory (message digest) starts at this release: store the highest
 * existing message id as DIGEST/START_AFTER_ID. A user without a stored
 * cursor begins after it, so existing histories are not re-read and billed
 * to the user's budget on the first pass. A fresh install stores 0.
 *
 * MAX() sits in a derived table: an aggregate in the outer SELECT returns a
 * row even when NOT EXISTS is false, and the insert would then hit the
 * unique key on a re-run instead of keeping the original start point.
 *
 * Galera-safe: raw idempotent DML, no Schema API.
 */
final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record DIGEST/START_AFTER_ID so long-term memory starts at the newest existing message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, 'DIGEST', 'START_AFTER_ID', CAST(latest.maxId AS CHAR)
              FROM (SELECT COALESCE(MAX(BID), 0) AS maxId FROM BMESSAGES) latest
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = 'DIGEST' AND BSETTING = 'START_AFTER_ID'
             )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'DIGEST' AND BSETTING = 'START_AFTER_ID'");
    }
}
