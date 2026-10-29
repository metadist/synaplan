<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The gateway's web-search ceiling rises from two rounds to four, on
 * installs that still hold the old seed. A value an administrator set
 * (anything but '2') is left alone; an install without the row gets the
 * seeder's value. Raw SQL only (Galera-safe; no Schema API).
 */
final class Version20261028130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Raise MESSAGES_GATEWAY.WEB_SEARCH_MAX_ROUNDS from 2 to 4 where it still holds the old seed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE BCONFIG SET BVALUE = '4' WHERE BOWNERID = 0 AND BGROUP = 'MESSAGES_GATEWAY' AND BSETTING = 'WEB_SEARCH_MAX_ROUNDS' AND TRIM(BVALUE) = '2'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE BCONFIG SET BVALUE = '2' WHERE BOWNERID = 0 AND BGROUP = 'MESSAGES_GATEWAY' AND BSETTING = 'WEB_SEARCH_MAX_ROUNDS' AND TRIM(BVALUE) = '4'",
        );
    }
}
