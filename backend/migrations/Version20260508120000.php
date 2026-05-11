<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire Anthropic Claude 3 Haiku (deprecated API model) from {@see \App\Model\ModelCatalog}.
 *
 * The catalog row BID 92 (`claude-3-haiku-20240307`) is removed from code; the seeder
 * never deletes BMODELS rows. Historical BMESSAGES rows may still reference BID 92 via
 * FK, so we deactivate the row instead of deleting it.
 *
 * Any {@see \App\Entity\Config} row with BGROUP = 'DEFAULTMODEL' and BVALUE = BID 92
 * (global defaults and per-user overrides) is repointed to Claude Haiku 4.5 chat
 * (BID 162) when that successor row exists (guarded EXISTS subquery), so nothing routes
 * to a deactivated model.
 */
final class Version20260508120000 extends AbstractMigration
{
    private const DEPRECATED_HAIKU_3_BID = 92;
    private const SUCCESSOR_HAIKU_45_BID = 162;

    public function getDescription(): string
    {
        return 'Deactivate deprecated Claude 3 Haiku (BID 92) and repoint DEFAULTMODEL bindings (any owner) still on BID 92 to Claude Haiku 4.5 (BID 162).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BCONFIG
               SET BVALUE = :successor
             WHERE BGROUP = 'DEFAULTMODEL'
               AND BVALUE = :deprecated
               AND EXISTS (SELECT 1 FROM BMODELS WHERE BID = :successor)
        SQL, [
            'deprecated' => (string) self::DEPRECATED_HAIKU_3_BID,
            'successor' => (string) self::SUCCESSOR_HAIKU_45_BID,
        ]);

        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BACTIVE = 0,
                   BSELECTABLE = 0,
                   BISDEFAULT = 0
             WHERE BID = :deprecated
               AND BPROVID = 'claude-3-haiku-20240307'
        SQL, [
            'deprecated' => self::DEPRECATED_HAIKU_3_BID,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Reactivate the deprecated row so it can reappear in the admin UI. We
        // intentionally do NOT undo the BCONFIG repoint from `up()`: we cannot know
        // which rows were auto-migrated vs intentionally set to BID 162 afterwards.
        // Same contract as {@see Version20260429120000::down()}.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BACTIVE = 1,
                   BSELECTABLE = 1
             WHERE BID = :deprecated
               AND BPROVID = 'claude-3-haiku-20240307'
        SQL, [
            'deprecated' => self::DEPRECATED_HAIKU_3_BID,
        ]);
    }
}
