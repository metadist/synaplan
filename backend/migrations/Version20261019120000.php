<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ownCloud.online uploads carry `source=owncloud`, which used to be outside
 * File::SOURCES and was stored as `web_upload`, so check-stale never found them
 * and the Library labelled them as web uploads.
 *
 * Only rows that came from the app are relabelled: a source id is set and the
 * owner has an ownCloud.online identity. Files from the app's shared mode have
 * no identity row and keep `web_upload` until the app's next update re-uploads
 * them. down() returns every `owncloud` row to `web_upload`, the only value the
 * previous release can read.
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Label ownCloud.online uploads that were stored as web uploads with their real source.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE BFILES SET BSOURCE = 'owncloud'
             WHERE BSOURCE = 'web_upload'
               AND BSOURCEID IS NOT NULL
               AND BUSERID IN (SELECT BUSERID FROM BEXTERNALIDENTITIES WHERE BSOURCE = 'owncloud')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE BFILES SET BSOURCE = 'web_upload' WHERE BSOURCE = 'owncloud'");
    }
}
