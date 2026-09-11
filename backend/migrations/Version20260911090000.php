<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Switch the Wave 1–5 feature flags ON for existing installs.
 *
 * The seeders now write these BCONFIG rows as `1`, but seeding is
 * insert-if-missing and never touches a row that already exists. Installs that
 * upgraded through the waves therefore still carry the `0` those releases
 * shipped — no admin toggle existed for most of them, so the value is the
 * shipped default, not an operator decision. This migration flips exactly the
 * seeded OFF values (`0`, `false`, ``) to `1`; an explicit `true`/`1` is left
 * alone, and any value that is not a recognised OFF is left alone too.
 *
 * Operators who want a feature off again use System configuration → Features
 * or pin it for automated deployments with `FEATURE_<GROUP>_<SETTING>=false`
 * (see docs/FEATURE_FLAGS.md). Module gates (`MODULES.GATE_*`) intentionally
 * stay as they are: the Intermezzo plan ships them default-off.
 *
 * Galera-safe: raw idempotent UPDATEs on the global row (BOWNERID = 0), no
 * Schema API.
 */
final class Version20260911090000 extends AbstractMigration
{
    /** @var list<array{0: string, 1: string}> BGROUP, BSETTING */
    private const FLAGS = [
        ['IAM', 'GROUPS_ENABLED'],
        ['IAM', 'SHARING_ENABLED'],
        ['IAM', 'DIRECTORY_SYNC_ENABLED'],
        ['IAM', 'GROUP_POLICIES_ENABLED'],
        ['AGENTS', 'ENABLED'],
        ['AGENTS', 'ROUTABLE_ENABLED'],
        // TOOLS.REGISTRY_ENABLED already seeds ON and has been an operator
        // kill switch since Wave 4 — an explicit OFF there is a decision.
        ['TOOLS', 'APPROVALS_ENABLED'],
        ['TOOLS', 'CUSTOM_HTTP_ENABLED'],
        ['WORKFLOWS', 'BUILDER_ENABLED'],
        ['PLATFORM_LINKS', 'ENABLED'],
        ['DOCUMENT_TOOLS', 'ENABLED'],
        ['BUNDLE', 'ENABLED'],
        ['DESKTOP_AGENT', 'ENABLED'],
        ['MULTITASK', 'URL_FETCH_ENABLED'],
    ];

    public function getDescription(): string
    {
        return 'Turn the Wave 1-5 feature flags (IAM, assistants, tools, workflows, platform links, document tools, bundle, desktop, URL watch) ON for existing installs';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::FLAGS as [$group, $setting]) {
            $this->addSql(
                "UPDATE BCONFIG SET BVALUE = '1' WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ? AND LOWER(TRIM(BVALUE)) IN ('0', 'false', 'off', 'no', '')",
                [$group, $setting],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // The pre-4.8 state was "seeded OFF, no operator decision recorded".
        // Reverting to it wholesale would also switch off features operators
        // enabled on purpose, so the rollback is a deliberate no-op: use the
        // Features tab or FEATURE_*=false to turn a feature off again.
        $this->addSql('SELECT 1');
    }
}
