<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen BCONFIG.BVALUE from VARCHAR(250) to LONGTEXT.
 *
 * LONGTEXT (not TEXT) because Doctrine maps `Types::TEXT` to LONGTEXT on
 * MariaDB — anything narrower fails CI's `doctrine:schema:validate` — and it
 * is the house convention for every other text column (BFILETEXT, BMETAVALUE,
 * plugin_data.BVALUE, ...).
 *
 * BCONFIG stores encrypted secrets (AES-256-CBC ciphertext, base64, IV
 * prepended) for the OpenAI-compatible endpoint registry, per-user Higgsfield
 * credentials and — new with this release — the DB-backed AI provider API
 * keys (group `provider_keys`). Ciphertext is ~1.4x the plaintext length plus
 * 24 bytes of IV overhead: a modern OpenAI project key (~160 chars) encrypts
 * to ~300 chars, and an encrypted endpoint JSON payload exceeds 250 chars for
 * any realistic base_url + key combination. VARCHAR(250) rejects those writes
 * with "Data too long" under STRICT_TRANS_TABLES (MariaDB default).
 *
 * Structure-only migration: no data is read, written, or shipped here.
 * Provider keys enter the database exclusively at runtime (admin UI or the
 * one-time env import in ProviderKeyStore) inside the operator's own install.
 *
 * Galera-safe on purpose: no reads of the injected `Schema $schema` (the DBAL
 * comparator throws TableDoesNotExist on the production cluster); the
 * existence/type check goes through `$this->connection` + information_schema,
 * so the migration is idempotent and re-runnable on any schema shape.
 */
final class Version20260729120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen BCONFIG.BVALUE to LONGTEXT so encrypted secrets (provider keys, endpoint configs) fit';
    }

    public function isTransactional(): bool
    {
        // DDL is non-transactional in MariaDB anyway; keep the runner honest.
        return false;
    }

    public function up(Schema $schema): void
    {
        $dataType = (string) $this->connection->fetchOne(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'BCONFIG' AND COLUMN_NAME = 'BVALUE'",
        );

        if ('longtext' !== strtolower($dataType)) {
            $this->addSql('ALTER TABLE BCONFIG MODIFY BVALUE LONGTEXT NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->warnIf(true, 'No-op: narrowing BVALUE back to VARCHAR(250) could truncate stored encrypted secrets.');
    }
}
