<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stop attached-photo look-changes from silently becoming file work.
 *
 * Rule 3a used to send every pixel edit (stamp, watermark, crop, resize) to
 * `code_run`. Composition requests ("etwas breiter, Vögel mittig") then ran
 * file work and failed. Fresh installs get the split from PromptCatalog;
 * this UPDATE patches the global tools:plan row when the old sentence is
 * still there. Galera-safe: single-row UPDATE, no Schema API.
 */
final class Version20261105140000 extends AbstractMigration
{
    private const OLD = '   A pixel edit on an attached image (stamp, watermark, crop, resize —'."\n"
        .'   "Stempel unten rechts drauf") is ALWAYS `code_run`, never `image_generation`.';

    private const NEW = '   A stamp, watermark, or scripted overlay on an attached image'."\n"
        .'   ("Stempel unten rechts drauf") is ALWAYS `code_run`, never `image_generation`.'."\n"
        .'   A composition, layout, or look change on an attached image ("make it wider and'."\n"
        .'   center the birds", "verändern, etwas breiter, Vögel mittig") is ALWAYS'."\n"
        .'   `image_generation` PIC2PIC — NEVER `code_run`. Crop/resize/scale without a'."\n"
        .'   stamp, script, or look instruction is ambiguous: plan `ask_user` first with'."\n"
        .'   two options (image edit vs file work), recommended image edit, then the'."\n"
        .'   matching node. Do not silently pick `code_run` for an attached photo.';

    private const MARKER = 'composition, layout, or look change';

    public function getDescription(): string
    {
        return 'Route attached-image look-changes to image_generation; ask before file work';
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
        if (str_contains($prompt, self::MARKER) || !str_contains($prompt, self::OLD)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [str_replace(self::OLD, self::NEW, $prompt), $bid]
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
        if (!str_contains($prompt, self::MARKER) || !str_contains($prompt, self::NEW)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [str_replace(self::NEW, self::OLD, $prompt), $bid]
        );
    }
}
