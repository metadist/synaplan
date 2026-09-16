<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed TRANSCRIPTION count limits so dictation/STT is not unlimited.
 *
 * Matches FILE_ANALYSIS volume: that was the previous gate on /upload-file
 * before purpose=dictation moved to TRANSCRIPTION (issue #1909).
 *
 * Galera-safe: raw addSql only, INSERT … WHERE NOT EXISTS. Operator overrides
 * are preserved.
 */
final class Version20260916120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed TRANSCRIPTION rate-limit rows (insert-if-missing)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $rows = [
            ['RATELIMITS_ANONYMOUS', 'TRANSCRIPTION_TOTAL', '3'],
            ['RATELIMITS_NEW', 'TRANSCRIPTION_TOTAL', '10'],
            ['RATELIMITS_PRO', 'TRANSCRIPTION_MONTHLY', '200'],
            ['RATELIMITS_TEAM', 'TRANSCRIPTION_MONTHLY', '1000'],
            ['RATELIMITS_BUSINESS', 'TRANSCRIPTION_MONTHLY', '5000'],
        ];

        foreach ($rows as [$group, $setting, $value]) {
            $this->addSql(
                <<<SQL
            INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            SELECT 0, '{$group}', '{$setting}', '{$value}'
              FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM BCONFIG
                  WHERE BOWNERID = 0 AND BGROUP = '{$group}' AND BSETTING = '{$setting}'
             )
            SQL
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BSETTING IN ('TRANSCRIPTION_TOTAL', 'TRANSCRIPTION_MONTHLY') AND BGROUP IN ('RATELIMITS_ANONYMOUS', 'RATELIMITS_NEW', 'RATELIMITS_PRO', 'RATELIMITS_TEAM', 'RATELIMITS_BUSINESS')");
    }
}
