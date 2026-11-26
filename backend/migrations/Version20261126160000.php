<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The officemaker answer used to be only "file created". Fresh installs get
 * BSUMMARY from PromptCatalog; this appends the same instruction to the
 * global row already stored on existing installs. Galera-safe: one UPDATE,
 * no Schema API.
 */
final class Version20261126160000 extends AbstractMigration
{
    private const MARKER = 'Always set BSUMMARY';

    private const APPENDIX = <<<'PROMPT'


## What changed (BSUMMARY)

Always set BSUMMARY inside the JSON object. Two to five sentences in the user's language saying what you created or changed: which parts you added, removed, or rewrote. The chat shows this next to the download. Do not paste the file body, and do not mention the JSON field names.
PROMPT;

    public function getDescription(): string
    {
        return 'Ask the officemaker prompt for a BSUMMARY of what changed';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BID, BPROMPT FROM BPROMPTS WHERE BTOPIC = ? AND BOWNERID = 0',
            ['officemaker']
        );
        if (!\is_array($row)) {
            return;
        }
        $prompt = $row['BPROMPT'] ?? null;
        $bid = $row['BID'] ?? null;
        if (!\is_string($prompt) || null === $bid || str_contains($prompt, self::MARKER)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [$prompt.self::APPENDIX, $bid]
        );
    }

    public function down(Schema $schema): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BID, BPROMPT FROM BPROMPTS WHERE BTOPIC = ? AND BOWNERID = 0',
            ['officemaker']
        );
        if (!\is_array($row)) {
            return;
        }
        $prompt = $row['BPROMPT'] ?? null;
        $bid = $row['BID'] ?? null;
        if (!\is_string($prompt) || null === $bid || !str_contains($prompt, self::APPENDIX)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE BPROMPTS SET BPROMPT = ? WHERE BID = ?',
            [str_replace(self::APPENDIX, '', $prompt), $bid]
        );
    }
}
