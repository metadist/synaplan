<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the deprecated OpenAI GPT-5.3 catalog entries (BIDs 193 chat + 194 pic2text).
 *
 * Background: GPT-5.3 has been superseded by GPT-5.4 (BIDs 180 chat + 181 pic2text)
 * and is being removed from {@see \App\Model\ModelCatalog}. The seeder never deletes
 * BMODELS rows on its own — and we cannot delete them here either, because BMESSAGES
 * still references BMODELS via FK. We therefore:
 *
 *   1. Flip the rows to BACTIVE=0 / BSELECTABLE=0 / BISDEFAULT=0 so they disappear
 *      from the admin UI and provider routing while historical message rows stay
 *      referentially intact.
 *   2. Repoint any BCONFIG DEFAULTMODEL bindings (operator overrides) that still
 *      target the deprecated BIDs at the GPT-5.4 successor of the matching tag, so
 *      no environment ends up with a default routed at a deactivated model. The
 *      UPDATE is guarded by a sub-SELECT that only touches rows whose successor is
 *      actually present — safety against partially-seeded test fixtures.
 *
 * No-ops on environments that never had GPT-5.3 seeded (UPDATEs simply match zero rows).
 */
final class Version20260429000000 extends AbstractMigration
{
    private const DEPRECATED_CHAT_BID = 193;
    private const DEPRECATED_VISION_BID = 194;
    private const SUCCESSOR_CHAT_BID = 180;
    private const SUCCESSOR_VISION_BID = 181;

    public function getDescription(): string
    {
        return 'Deactivate deprecated GPT-5.3 BMODELS rows (BID 193, 194) and migrate operator DEFAULTMODEL overrides to GPT-5.4 (BID 180, 181).';
    }

    public function up(Schema $schema): void
    {
        // Repoint operator-overridden DEFAULTMODEL bindings BEFORE deactivating the
        // rows, so we never leave BCONFIG pointing at an inactive model — even if
        // the migration were to be aborted between statements.
        $this->addSql(<<<'SQL'
            UPDATE BCONFIG
               SET BVALUE = :successor
             WHERE BGROUP = 'DEFAULTMODEL'
               AND BVALUE = :deprecated
               AND EXISTS (SELECT 1 FROM BMODELS WHERE BID = :successor)
        SQL, [
            'deprecated' => (string) self::DEPRECATED_CHAT_BID,
            'successor' => (string) self::SUCCESSOR_CHAT_BID,
        ]);

        $this->addSql(<<<'SQL'
            UPDATE BCONFIG
               SET BVALUE = :successor
             WHERE BGROUP = 'DEFAULTMODEL'
               AND BVALUE = :deprecated
               AND EXISTS (SELECT 1 FROM BMODELS WHERE BID = :successor)
        SQL, [
            'deprecated' => (string) self::DEPRECATED_VISION_BID,
            'successor' => (string) self::SUCCESSOR_VISION_BID,
        ]);

        // Hide the deprecated rows from the UI / provider routing without deleting
        // them: BMESSAGES.BMODEL_ID still has FK references to historical entries.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BACTIVE = 0,
                   BSELECTABLE = 0,
                   BISDEFAULT = 0
             WHERE BID IN (:chat, :vision)
        SQL, [
            'chat' => self::DEPRECATED_CHAT_BID,
            'vision' => self::DEPRECATED_VISION_BID,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Reactivate the rows so they reappear in the admin UI. We intentionally
        // do NOT undo the BCONFIG repoint: GPT-5.4 is a strict superset and we have
        // no way of knowing which operators had explicitly chosen GPT-5.3.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BACTIVE = 1,
                   BSELECTABLE = 1
             WHERE BID IN (:chat, :vision)
        SQL, [
            'chat' => self::DEPRECATED_CHAT_BID,
            'vision' => self::DEPRECATED_VISION_BID,
        ]);
    }
}
