<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reset every prompt-level "Internet search: always on" flag to "automatic".
 *
 * A prompt setting no longer forces a web search on every message; it only
 * allows it, and the classifier decides per message whether fresh
 * information is needed (WebSearchTopicPolicy). Most stored `true` values
 * came from the pre-selected checkbox of the old prompt editor and were
 * turned into a hard force by Version20260525220000, so "Hi" triggered a
 * search. Deleting the row is the "automatic" state; an explicit
 * `tool_internet=0` (always off) is kept.
 *
 * Galera-safe: raw idempotent DML, no Schema API.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reset BPROMPTMETA tool_internet=1 ("always on") to automatic web search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM BPROMPTMETA
             WHERE BMETAKEY IN ('tool_internet', 'tool_internet_search')
               AND BMETAVALUE IN ('1', 'true')
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Intentionally a no-op: "always on" is no longer a supported state,
        // and the deleted rows cannot be told apart from never-set ones.
    }
}
