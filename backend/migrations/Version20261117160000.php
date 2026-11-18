<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opens the one-time notice on every existing connection whose writes were
 * off: write tools now follow the class default (auto), so the owner is told
 * once and can review the permissions.
 *
 * Idempotent: only a connection whose policy is still the untouched default
 * matches, and a fresh install has no rows at this point.
 */
final class Version20261117160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Open the one-time tool permissions notice on existing MCP connections with writes off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE BMCPSERVERS SET BTOOLPOLICY = '{\"v\":1,\"tools\":{},\"seen\":[],\"notice\":true}' WHERE BALLOWWRITE = 0 AND BTOOLPOLICY = '{}'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE BMCPSERVERS SET BTOOLPOLICY = '{}' WHERE BTOOLPOLICY = '{\"v\":1,\"tools\":{},\"seen\":[],\"notice\":true}'");
    }
}
