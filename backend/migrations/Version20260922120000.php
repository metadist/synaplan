<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turn off the instance-wide "everyone" audience where anyone can sign up.
 *
 * BCONFIG defaults are insert-if-missing, so changing the seeder does not
 * reach an install that already has IAM.EVERYONE_SHARES. On an open
 * registration instance that audience is every self-registered account
 * (#2096). This sets the global row to `disabled`. Invite-only and SSO-only
 * installs (registration explicitly off, including an env pin) keep whatever
 * value they stored. Per-user rows are left alone; a global `disabled` cannot
 * be widened by one. No row is created — a fresh install gets its value from
 * the seeder.
 *
 * Share rows are never deleted. A grant a person wrote stays stored and inert
 * while the audience is off. Platform distribution is recognized by
 * BGRANTEDBY = 0 (Share::PLATFORM_GRANTOR): seeded system assistants already
 * carry it, and the plugin-pack installer writes it from this release on.
 * Installer rows written before this release carry the enabling admin's id
 * instead; step 2 restores their provenance so plugin assistants keep reaching
 * every account after the switch, on every policy value.
 *
 * Registration precedence matches {@see \App\Service\RegistrationConfig}:
 * an explicit REGISTRATION_ENABLED env value wins, then ACCESS.REGISTRATION_ENABLED,
 * then the built-in default (open).
 *
 * Galera-safe: conditional DML through the connection, raw idempotent
 * addSql, no Schema API.
 */
final class Version20260922120000 extends AbstractMigration
{
    private const DISABLE_EVERYONE = <<<'SQL'
        UPDATE BCONFIG SET BVALUE = 'disabled'
        WHERE BOWNERID = 0 AND BGROUP = 'IAM' AND BSETTING = 'EVERYONE_SHARES' AND BVALUE <> 'disabled'
        SQL;

    /**
     * Same decision as DISABLE_EVERYONE, plus "registration is not explicitly
     * closed". A missing row, an empty value, or anything unrecognized stays
     * open. Closed spellings match filter_var(..., FILTER_VALIDATE_BOOL).
     * The subquery is a derived table so MariaDB accepts the self-reference.
     */
    private const DISABLE_WHEN_OPEN = <<<'SQL'
        UPDATE BCONFIG
        SET BVALUE = 'disabled'
        WHERE BOWNERID = 0
          AND BGROUP = 'IAM'
          AND BSETTING = 'EVERYONE_SHARES'
          AND BVALUE <> 'disabled'
          AND NOT EXISTS (
            SELECT 1 FROM (
              SELECT BVALUE FROM BCONFIG
              WHERE BOWNERID = 0 AND BGROUP = 'ACCESS' AND BSETTING = 'REGISTRATION_ENABLED'
            ) AS reg
            WHERE LOWER(reg.BVALUE) IN ('0', 'false', 'off', 'no')
          )
        SQL;

    /**
     * Everyone-shares the plugin-pack installer wrote before it recorded
     * platform provenance. The installer's signature is exact and nothing else
     * produces it: kind `agent`, subject `everyone`, permission `use`, on an
     * assistant whose BSOURCE is `plugin:<id>` (only PluginAgentInstaller
     * creates those), granted by that assistant's owner (the installer imports
     * as the enabling admin and grants as the same person). The installer
     * writes this row for every pack item, so on a plugin assistant this row
     * *is* the installer's row; a person re-granting it by hand produces the
     * same key and is merged into it. Anything else — a different permission,
     * a different grantor, a manual or imported assistant — is a person's
     * grant and is left to the policy.
     *
     * Only agent rows enter the derived table, so the numeric cast never sees
     * a folder or conversation id. The matching ids are applied by integer
     * primary key (see up()), so the UPDATE itself carries no type conversion.
     */
    private const LEGACY_INSTALLER_SHARES = <<<'SQL'
        SELECT sh.BID
        FROM (
          SELECT BID, BRESOURCEID, BGRANTEDBY
          FROM BSHARES
          WHERE BRESOURCEKIND = 'agent'
            AND BSUBJECTTYPE = 'everyone'
            AND BPERMISSION = 'use'
            AND BGRANTEDBY <> 0
        ) AS sh
        INNER JOIN BAGENTS a
          ON a.BID = CAST(sh.BRESOURCEID AS UNSIGNED)
        WHERE a.BOWNERID = sh.BGRANTEDBY
          AND a.BSOURCE LIKE 'plugin:%'
        SQL;

    private const MARK_PLATFORM_BATCH = 500;

    /** Matches nothing. A SELECT would leave an unbuffered MariaDB cursor open. */
    private const NOOP = 'UPDATE BCONFIG SET BVALUE = BVALUE WHERE 1 = 0';

    public function getDescription(): string
    {
        return 'Disable instance-wide everyone shares on installs with open self-registration; mark plugin-pack shares as platform grants (#2096)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->disableEveryoneWhereAnyoneCanSignUp();
        $this->markLegacyInstallerSharesAsPlatform();
    }

    public function down(Schema $schema): void
    {
        // Restoring any_owner would reopen the audience on a public instance,
        // and a plugin share marked as the platform's is correct on any policy.
        // An operator switches the audience back under System configuration → Sharing.
        $this->addSql(self::NOOP);
    }

    private function disableEveryoneWhereAnyoneCanSignUp(): void
    {
        $raw = trim((string) ($_ENV['REGISTRATION_ENABLED'] ?? ''));
        if ('' !== $raw) {
            $open = filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
            $this->addSql($open ? self::DISABLE_EVERYONE : self::NOOP);

            return;
        }

        $this->addSql(self::DISABLE_WHEN_OPEN);
    }

    /**
     * Runs on every policy value: the mark only says who wrote the row, and a
     * plugin assistant is meant to reach every account whether or not people
     * may share with everyone. Idempotent — a marked row has BGRANTEDBY = 0
     * and no longer matches.
     */
    private function markLegacyInstallerSharesAsPlatform(): void
    {
        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->connection->fetchFirstColumn(self::LEGACY_INSTALLER_SHARES),
        ), static fn (int $id): bool => $id > 0));

        if ([] === $ids) {
            return;
        }

        foreach (array_chunk($ids, self::MARK_PLATFORM_BATCH) as $batch) {
            $this->addSql(sprintf(
                'UPDATE BSHARES SET BGRANTEDBY = 0 WHERE BSUBJECTTYPE = \'everyone\' AND BGRANTEDBY <> 0 AND BID IN (%s)',
                implode(', ', $batch),
            ));
        }
    }
}
