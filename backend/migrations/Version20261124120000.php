<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keep widgets that already pass a visitor id working without a signature.
 *
 * A visitor id now only counts when the embedding site signed it. A widget
 * that fetches a `{externalUserId}` URL today (its own user-data URL, or an
 * API response in the flow rules of its task prompt) was embedded before
 * signing existed, so it gets the `allowUnsignedVisitorId` mark and goes on
 * as before until its owner turns signing on. Every other widget, and every
 * widget created later, signs.
 *
 * Data only, raw SQL, idempotent: a marked widget is skipped on a re-run.
 */
final class Version20261124120000 extends AbstractMigration
{
    private const MARK = 'allowUnsignedVisitorId';
    private const PLACEHOLDER = '%{externalUserId}%';

    public function getDescription(): string
    {
        return 'Mark widgets that already pass a visitor id as allowed to send it unsigned';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $select = $this->connection->prepare(
            'SELECT w.BID, w.BCONFIG
               FROM BWIDGETS w
              WHERE w.BCONFIG LIKE :placeholder
                 OR EXISTS (
                        SELECT 1
                          FROM BPROMPTS p
                          JOIN BPROMPTMETA m ON m.BPROMPTID = p.BID AND m.BMETAKEY = :metaKey
                         WHERE p.BTOPIC = w.BTASKPROMPT
                           AND p.BOWNERID IN (w.BOWNERID, 0)
                           AND m.BMETAVALUE LIKE :placeholder
                    )'
        );
        $select->bindValue('placeholder', self::PLACEHOLDER);
        $select->bindValue('metaKey', 'widgetFlowRules');

        $update = $this->connection->prepare('UPDATE BWIDGETS SET BCONFIG = :config WHERE BID = :bid');

        foreach ($select->executeQuery()->fetchAllAssociative() as $row) {
            $config = json_decode((string) $row['BCONFIG'], true);
            if (!\is_array($config) || true === ($config[self::MARK] ?? null)) {
                continue;
            }

            $config[self::MARK] = true;
            $update->bindValue('config', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $update->bindValue('bid', (int) $row['BID']);
            $update->executeStatement();
        }
    }

    public function down(Schema $schema): void
    {
        // The mark stays: removing it would stop those widgets' visitor lookups.
    }
}
