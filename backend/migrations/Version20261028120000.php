<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tool mode for the models that passed conformance, on existing installs.
 *
 * RESEARCH.TOOL_MODE_MODELS is replaced only where it still holds the empty
 * list the earlier seeder wrote. A list an administrator filled in is never
 * touched; an install without the row gets the seeder's value. Raw SQL only
 * (Galera-safe; no Schema API).
 */
final class Version20261028120000 extends AbstractMigration
{
    private const PROVEN = '["anthropic:claude-sonnet-5-5:chat","anthropic:claude-sonnet-5:chat"]';

    public function getDescription(): string
    {
        return 'Fill RESEARCH.TOOL_MODE_MODELS with the proven models where it is still empty';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE BCONFIG SET BVALUE = :proven WHERE BOWNERID = 0 AND BGROUP = 'RESEARCH' AND BSETTING = 'TOOL_MODE_MODELS' AND TRIM(BVALUE) IN ('', '[]')",
            ['proven' => self::PROVEN],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE BCONFIG SET BVALUE = '[]' WHERE BOWNERID = 0 AND BGROUP = 'RESEARCH' AND BSETTING = 'TOOL_MODE_MODELS' AND BVALUE = :proven",
            ['proven' => self::PROVEN],
        );
    }
}
