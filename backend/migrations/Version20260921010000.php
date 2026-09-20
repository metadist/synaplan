<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Teach the AI planner (tools:plan) that an explicit script-execution request
 * beats the image-edit rule.
 *
 * Rule 3 routes every image generate/edit to `image_generation` — including
 * "write a python script and apply a headline across the attached image", so
 * the planner sent scripted file work to the AI image model (PIC2PIC) instead
 * of `code_run`. Rule 3a carves script/program/code execution out (fresh
 * installs get it from PromptCatalog::planPrompt()).
 *
 * Surgical + idempotent: only touches the global row, only when both anchors
 * are present verbatim (operator-customized prompts are left alone), and
 * skips when the rule is already there. Galera-safe: single-row UPDATE via
 * the connection (conditional DML), no Schema API introspection.
 */
final class Version20260921010000 extends AbstractMigration
{
    private const ANCHOR_RULE3 = '3. Image generate/edit → `image_generation`. To EDIT an image produced by an'."\n"
        .'   earlier node ("make a logo, then make it blue"), add a second';

    private const NEW_RULE3 = '3. Image generate/edit → `image_generation` (UNLESS the user asked for a script/program — rule 3a wins).'."\n"
        .'   To EDIT an image produced by an earlier node ("make a logo, then make it blue"), add a second';

    private const ANCHOR_PIC2PIC = '   The `image` input turns it into an image-to-image edit (PIC2PIC).'."\n";

    private const BLOCK_3A = '3a. Script / program / code the user asked to be RUN (not just shown) → `code_run` —'."\n"
        .'   NEVER `image_generation`, NEVER `chat`, even for an image edit (this rule wins over'."\n"
        .'   rules 3 and 6). Triggers: "write a python script and apply/run/execute it ...",'."\n"
        .'   "run this code on the file", "compute/convert/totals ... with code",'."\n"
        .'   "führe das Skript auf dieser Datei aus". The node runs a REAL program on COPIES of'."\n"
        .'   the attached files and returns result files — a scripted watermark stays pixel-exact'."\n"
        .'   while an AI re-render changes the picture. Set `params.script` to the COMPLETE program'."\n"
        .'   (multi-line, real newlines — never a one-liner, never `;`-joined) and `params.image` to'."\n"
        .'   "python" or "node". OMIT `params.inputFileIds`: the runner mounts this turn\'s attached'."\n"
        .'   files automatically — you are never given their numeric ids, so never invent any.'."\n"
        .'   Only files written under `/out/` are kept. Script text ONLY ("show me the code", no'."\n"
        .'   run/apply/execute verb) stays a plain `chat` answer.'."\n";

    private const MARKER = 'rule 3a wins';

    public function getDescription(): string
    {
        return 'Add script-execution rule 3a to planner routing rules (tools:plan)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BID, BPROMPT FROM BPROMPTS WHERE BTOPIC = ? AND BOWNERID = 0',
            ['tools:plan']
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
        if (!str_contains($prompt, self::ANCHOR_RULE3) || !str_contains($prompt, self::ANCHOR_PIC2PIC)) {
            return;
        }
        $updated = str_replace(self::ANCHOR_RULE3, self::NEW_RULE3, $prompt);
        $updated = str_replace(self::ANCHOR_PIC2PIC, self::ANCHOR_PIC2PIC.self::BLOCK_3A, $updated);
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [$updated, $bid]
        );
    }

    public function down(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BID, BPROMPT FROM BPROMPTS WHERE BTOPIC = ? AND BOWNERID = 0',
            ['tools:plan']
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
        $updated = str_replace(self::NEW_RULE3, self::ANCHOR_RULE3, $prompt);
        $updated = str_replace(self::BLOCK_3A, '', $updated);
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [$updated, $bid]
        );
    }
}
