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
 * the seeder. Existing share rows are not deleted and are not rewritten:
 * a grant an account created stays inert while the audience is off, and a
 * plugin install records its own grant as a system grant (grantedBy 0).
 *
 * Registration precedence matches {@see \App\Service\RegistrationConfig}:
 * an explicit REGISTRATION_ENABLED env value wins, then ACCESS.REGISTRATION_ENABLED,
 * then the built-in default (open).
 *
 * Galera-safe: one conditional UPDATE via the connection, no Schema API.
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

    /** Matches nothing. A SELECT would leave an unbuffered MariaDB cursor open. */
    private const NOOP = 'UPDATE BCONFIG SET BVALUE = BVALUE WHERE 1 = 0';

    public function getDescription(): string
    {
        return 'Disable instance-wide everyone shares on installs with open self-registration (#2096)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $raw = trim((string) ($_ENV['REGISTRATION_ENABLED'] ?? ''));
        if ('' !== $raw) {
            $open = filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
            $this->addSql($open ? self::DISABLE_EVERYONE : self::NOOP);

            return;
        }

        $this->addSql(self::DISABLE_WHEN_OPEN);
    }

    public function down(Schema $schema): void
    {
        // Restoring any_owner would reopen the audience on a public instance.
        // An operator switches it back under System configuration → Sharing.
        $this->addSql(self::NOOP);
    }
}
