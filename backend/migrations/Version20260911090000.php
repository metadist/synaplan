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
 * shipped. This release turns the wave features on for every install, so the
 * global row (BOWNERID = 0) of each flag is set to `1` whenever it holds a
 * recognised OFF spelling (`0`, `false`, `off`, `no`, ``). BCONFIG records no
 * provenance, so a `0` an operator set by hand cannot be told apart from the
 * seeded one and is flipped as well — this is the documented upgrade
 * behaviour, not an oversight. Anything else (`1`, `true`, garbage) and every
 * per-user or group row is left untouched, and no row is created.
 *
 * Deployments that need a feature to stay off pin it before upgrading with
 * `FEATURE_<GROUP>_<SETTING>=false` (the environment wins over the database,
 * so the flipped row is inert) or switch it off again afterwards under System
 * configuration → Features (see docs/FEATURE_FLAGS.md). Module gates
 * (`MODULES.GATE_*`) and the `TOOLS.REGISTRY_ENABLED` kill switch are not
 * touched: gates ship default-off, and the registry has seeded ON since Wave 4,
 * so an OFF there is an operator decision by construction.
 *
 * Galera-safe: raw idempotent UPDATEs on the global row, no Schema API.
 */
final class Version20260911090000 extends AbstractMigration
{
    /** @var list<array{0: string, 1: string}> BGROUP, BSETTING */
    public const FLAGS = [
        ['IAM', 'GROUPS_ENABLED'],
        ['IAM', 'SHARING_ENABLED'],
        ['IAM', 'DIRECTORY_SYNC_ENABLED'],
        ['IAM', 'GROUP_POLICIES_ENABLED'],
        ['AGENTS', 'ENABLED'],
        ['AGENTS', 'ROUTABLE_ENABLED'],
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
