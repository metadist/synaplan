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
 * this UPDATE patches the global tools:plan row when an older 3a sentence is
 * still there. Galera-safe: single-row UPDATE, no Schema API.
 */
final class Version20261105140000 extends AbstractMigration
{
    private const OLD_ORIGINAL = '   `file_analysis`. A pixel edit on an attached image (stamp, watermark, crop, resize —'."\n"
        .'   "Stempel unten rechts drauf") is ALWAYS `code_run`, never `image_generation`.';

    private const OLD_FOLLOW_UP = '   `file_analysis`.    A pixel edit on an attached image that must stay unchanged (stamp, watermark,'."\n"
        .'   crop, resize — "Stempel unten rechts drauf. Das vorhandene Bild, keins neu'."\n"
        .'   malen") is ALWAYS `code_run`, never `image_generation`. A follow-up that'."\n"
        .'   changes what the picture SHOWS (pose, subjects, style, background) is rule 3:'."\n"
        .'   `image_generation` PIC2PIC — even when it also asks for text in a corner'."\n"
        .'   ("lass den Vogel fliegen und schreib SYNAPLAN oben rechts").';

    private const NEW = '   `file_analysis`. A stamp, watermark, or scripted overlay on an attached image'."\n"
        .'   ("Stempel unten rechts drauf. Das vorhandene Bild, keins neu malen") is ALWAYS'."\n"
        .'   `code_run`, never `image_generation`. A composition, layout, or look change'."\n"
        .'   on an attached image ("make it wider and center the birds", "verändern, etwas'."\n"
        .'   breiter, Vögel mittig") is ALWAYS `image_generation` PIC2PIC — NEVER `code_run`.'."\n"
        .'   A follow-up that changes what the picture SHOWS (pose, subjects, style,'."\n"
        .'   background) is also `image_generation` PIC2PIC — even when it also asks for'."\n"
        .'   text in a corner ("lass den Vogel fliegen und schreib SYNAPLAN oben rechts").'."\n"
        .'   Crop/resize/scale without a stamp, script, or look instruction is ambiguous:'."\n"
        .'   plan `ask_user` first with two options (image edit vs file work), recommended'."\n"
        .'   image edit, then the matching node. Do not silently pick `code_run` for an'."\n"
        .'   attached photo.';

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
        if (str_contains($prompt, self::MARKER)) {
            return;
        }
        $updated = $prompt;
        if (str_contains($prompt, self::OLD_FOLLOW_UP)) {
            $updated = str_replace(self::OLD_FOLLOW_UP, self::NEW, $prompt);
        } elseif (str_contains($prompt, self::OLD_ORIGINAL)) {
            $updated = str_replace(self::OLD_ORIGINAL, self::NEW, $prompt);
        } else {
            return;
        }
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
        if (!str_contains($prompt, self::MARKER) || !str_contains($prompt, self::NEW)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [str_replace(self::NEW, self::OLD_FOLLOW_UP, $prompt), $bid]
        );
    }
}
