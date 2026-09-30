<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rewrite legacy "Generated image/video/audio: …" OUT texts to media markers.
 *
 * Galera-safe: raw addSql only, no Schema API. Idempotent: a second run finds
 * no matching BTEXT prefixes. When media_prompt meta is missing, the text after
 * the English prefix is stored as media_prompt before BTEXT is rewritten.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewrite Generated image/video/audio OUT texts to __*_GENERATED__ markers';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Image: seed media_prompt from the legacy prose when missing.
        $this->addSql(<<<'SQL'
            INSERT INTO BMESSAGEMETA (BMESSAGEID, BMETAKEY, BMETAVALUE, BCREATED)
            SELECT
                m.BID,
                'media_prompt',
                TRIM(SUBSTRING_INDEX(SUBSTRING(m.BTEXT, CHAR_LENGTH('Generated image: ') + 1), '\n', 1)),
                UNIX_TIMESTAMP()
            FROM BMESSAGES m
            WHERE m.BDIRECT = 'OUT'
              AND m.BTEXT LIKE 'Generated image: %'
              AND NOT EXISTS (
                  SELECT 1 FROM BMESSAGEMETA meta
                  WHERE meta.BMESSAGEID = m.BID AND meta.BMETAKEY = 'media_prompt'
              )
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE BMESSAGES
            SET BTEXT = CASE
                WHEN LOCATE('\n', BTEXT) > 0 THEN CONCAT('__IMAGE_GENERATED__', SUBSTRING(BTEXT, LOCATE('\n', BTEXT)))
                ELSE '__IMAGE_GENERATED__'
            END
            WHERE BDIRECT = 'OUT'
              AND BTEXT LIKE 'Generated image: %'
        SQL);

        // Video
        $this->addSql(<<<'SQL'
            INSERT INTO BMESSAGEMETA (BMESSAGEID, BMETAKEY, BMETAVALUE, BCREATED)
            SELECT
                m.BID,
                'media_prompt',
                TRIM(SUBSTRING_INDEX(SUBSTRING(m.BTEXT, CHAR_LENGTH('Generated video: ') + 1), '\n', 1)),
                UNIX_TIMESTAMP()
            FROM BMESSAGES m
            WHERE m.BDIRECT = 'OUT'
              AND m.BTEXT LIKE 'Generated video: %'
              AND NOT EXISTS (
                  SELECT 1 FROM BMESSAGEMETA meta
                  WHERE meta.BMESSAGEID = m.BID AND meta.BMETAKEY = 'media_prompt'
              )
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE BMESSAGES
            SET BTEXT = CASE
                WHEN LOCATE('\n', BTEXT) > 0 THEN CONCAT('__VIDEO_GENERATED__', SUBSTRING(BTEXT, LOCATE('\n', BTEXT)))
                ELSE '__VIDEO_GENERATED__'
            END
            WHERE BDIRECT = 'OUT'
              AND BTEXT LIKE 'Generated video: %'
        SQL);

        // Audio (legacy prose; newer rows already use __AUDIO_GENERATED__)
        $this->addSql(<<<'SQL'
            INSERT INTO BMESSAGEMETA (BMESSAGEID, BMETAKEY, BMETAVALUE, BCREATED)
            SELECT
                m.BID,
                'media_prompt',
                TRIM(SUBSTRING_INDEX(SUBSTRING(m.BTEXT, CHAR_LENGTH('Generated audio: ') + 1), '\n', 1)),
                UNIX_TIMESTAMP()
            FROM BMESSAGES m
            WHERE m.BDIRECT = 'OUT'
              AND m.BTEXT LIKE 'Generated audio: %'
              AND NOT EXISTS (
                  SELECT 1 FROM BMESSAGEMETA meta
                  WHERE meta.BMESSAGEID = m.BID AND meta.BMETAKEY = 'media_prompt'
              )
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE BMESSAGES
            SET BTEXT = CASE
                WHEN LOCATE('\n', BTEXT) > 0 THEN CONCAT('__AUDIO_GENERATED__', SUBSTRING(BTEXT, LOCATE('\n', BTEXT)))
                ELSE '__AUDIO_GENERATED__'
            END
            WHERE BDIRECT = 'OUT'
              AND BTEXT LIKE 'Generated audio: %'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible data rewrite — prompts remain in media_prompt meta.
    }
}
