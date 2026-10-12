<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Point the global image defaults at GPT Image 2.5 Flare when they still
 * name Nano Banana 2 (#2407).
 *
 * Decisions recorded with the change: TEXT2PIC ships as Flare (same model
 * and key as PIC2PIC), the fallback order is the explicit list in
 * ModelCatalog::IMAGE_FALLBACK_KEYS, and only the global row moves.
 * A user or group binding stays. The update runs only while the row still
 * holds BID 371 and Flare (BID 348) is present and active.
 */
final class Version20261012120000 extends AbstractMigration
{
    private const NANO_BANANA_2 = 371;
    private const FLARE = 348;
    private const FLARE_PROVID = 'gpt-image-2.5-flare';

    public function getDescription(): string
    {
        return 'Repoint the global TEXT2PIC and PIC2PIC defaults from Nano Banana 2 to GPT Image 2.5 Flare.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BCONFIG
               SET BVALUE = :flare
             WHERE BOWNERID = 0
               AND BGROUP = 'DEFAULTMODEL'
               AND BSETTING IN ('TEXT2PIC', 'PIC2PIC')
               AND BVALUE = :nano
               AND EXISTS (
                   SELECT 1
                     FROM BMODELS
                    WHERE BID = :flareId
                      AND BPROVID = :providerId
                      AND BTAG = 'text2pic'
                      AND BACTIVE = 1
               )
        SQL, [
            'flare' => (string) self::FLARE,
            'nano' => (string) self::NANO_BANANA_2,
            'flareId' => self::FLARE,
            'providerId' => self::FLARE_PROVID,
        ], [
            'flareId' => ParameterType::INTEGER,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Not revertible: a row that an operator later set back to Nano Banana 2
        // must not be moved again, and the previous value is not stored.
    }
}
