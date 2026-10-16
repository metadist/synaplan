<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Teach the AI sorter (tools:sort) that running code on files is multi-step.
 *
 * Rule 12 forces BMULTI=1 for actions a written reply cannot deliver — but
 * file work was missing, so "run python on this file" voted single-step and
 * TaskPlanExecutor skipped the planner, degrading code_run into a chat answer
 * that only talks about running code. Adds the missing bullet to the global
 * sorter prompt (fresh installs get it from PromptCatalog::sortPrompt()).
 *
 * Surgical + idempotent: only touches the global row, only when the anchor
 * text is present verbatim (operator-customized prompts are left alone), and
 * skips when the bullet is already there. Galera-safe: single-row UPDATE via
 * the connection (conditional DML), no Schema API introspection.
 */
final class Version20260920210000 extends AbstractMigration
{
    private const ANCHOR = '   - Saving the result to a connected folder / Nextcloud'."\n"
        .'     ("save it to my Nextcloud", "lege es in meinen Nextcloud-Account")';

    private const BULLET = "\n"
        .'   - Running code or a script on the user\'s files (file work)'."\n"
        .'     ("run python on this file and tell me ...", "compute the totals with code",'."\n"
        .'     "führe das Skript auf dieser Datei aus")';

    private const MARKER = 'Running code or a script on the user\'s files (file work)';

    public function getDescription(): string
    {
        return 'Add file-work bullet to sorter BMULTI rule (tools:sort)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BID, BPROMPT FROM BPROMPTS WHERE BTOPIC = ? AND BOWNERID = 0',
            ['tools:sort']
        );
        if (!\is_array($row)) {
            return;
        }
        $prompt = $row['BPROMPT'] ?? null;
        $bid = $row['BID'] ?? null;
        if (!\is_string($prompt) || null === $bid) {
            return;
        }
        if (str_contains($prompt, self::MARKER)) {
            return;
        }
        if (!str_contains($prompt, self::ANCHOR)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [str_replace(self::ANCHOR, self::ANCHOR.self::BULLET, $prompt), $bid]
        );
    }

    public function down(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BID, BPROMPT FROM BPROMPTS WHERE BTOPIC = ? AND BOWNERID = 0',
            ['tools:sort']
        );
        if (!\is_array($row)) {
            return;
        }
        $prompt = $row['BPROMPT'] ?? null;
        $bid = $row['BID'] ?? null;
        if (!\is_string($prompt) || null === $bid) {
            return;
        }
        if (!str_contains($prompt, self::MARKER)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [str_replace(self::BULLET, '', $prompt), $bid]
        );
    }
}
