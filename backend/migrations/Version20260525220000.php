<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Canonicalise tool-flag keys in BPROMPTMETA.
 *
 * Historic Vue config wrote `tool_internet_search` and `tool_files_search`
 * to `BPROMPTMETA`, while the routing layer (`SynapseRouter`,
 * `MessageSorter`, `MessageProcessor`) only reads the canonical short
 * names `tool_internet` and `tool_files`. The user-facing "Internet
 * Search" / "Files Search" toggles in the Task Prompts settings page
 * therefore never enabled web search via the streaming chat pipeline
 * (the negative gate in `MessageProcessor::processStream()` honoured
 * `tool_internet_search`, but no positive trigger ever did).
 *
 * This migration renames every legacy row in place so existing installs
 * benefit from the bug fix without depending on PromptService's
 * read-time alias fallback. Idempotent: re-running is a no-op once the
 * legacy keys are gone. Rows where both the legacy and the canonical
 * key already exist for the same prompt are deduplicated by dropping
 * the legacy row.
 *
 * Note: `BPROMPTMETA` has no unique key on (BPROMPTID, BMETAKEY), only
 * separate indexes — so a naive UPDATE would risk creating duplicate
 * canonical rows. The dedupe DELETE runs first to prevent that.
 */
final class Version20260525220000 extends AbstractMigration
{
    /**
     * Map of legacy key → canonical key. Keep in sync with
     * {@see \App\Service\PromptService::METADATA_KEY_ALIASES}.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'tool_internet_search' => 'tool_internet',
        'tool_files_search' => 'tool_files',
    ];

    public function getDescription(): string
    {
        return 'Canonicalise BPROMPTMETA tool-flag keys: '
            .'tool_internet_search → tool_internet, tool_files_search → tool_files. '
            .'Existing rows are renamed in place; duplicates collapse onto the canonical row.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ALIASES as $legacy => $canonical) {
            // 1) Drop legacy rows where a canonical row already exists for the
            //    same prompt. After this DELETE the rename is safe (no
            //    accidental duplicates appear).
            $this->addSql(<<<'SQL'
                DELETE legacy
                  FROM BPROMPTMETA legacy
                  JOIN BPROMPTMETA canonical
                    ON canonical.BPROMPTID = legacy.BPROMPTID
                   AND canonical.BMETAKEY = :canonical
                 WHERE legacy.BMETAKEY = :legacy
                SQL, [
                'legacy' => $legacy,
                'canonical' => $canonical,
            ]);

            // 2) Rename the remaining legacy rows to the canonical key.
            $this->addSql(<<<'SQL'
                UPDATE BPROMPTMETA
                   SET BMETAKEY = :canonical
                 WHERE BMETAKEY = :legacy
                SQL, [
                'canonical' => $canonical,
                'legacy' => $legacy,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Downgrade is intentionally a no-op. We do NOT rename canonical rows
        // back to the legacy aliases because that would re-introduce the
        // routing bug, and we cannot tell which canonical rows originated as
        // aliases vs. were always written as the canonical name.
    }
}
