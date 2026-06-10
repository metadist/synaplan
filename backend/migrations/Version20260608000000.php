<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the embedding-based routing experiment in BPROMPTS — phase 1 of 2.
 *
 * The vector-similarity routing prototype (BKEYWORDS for embedding synonyms,
 * BENABLED as a routing-pool soft-disable, plus the granular routing topics)
 * was retired in favour of the AI sorter as the single classifier and the
 * multi-task plan (DAG) router.
 *
 * Two-phase (expand/contract) on purpose: migrations run on FIRST container
 * start against the SHARED Galera DB, while web1/web2/web3 roll one node at a
 * time. The previous release's Prompt entity still maps BKEYWORDS/BENABLED in
 * every SELECT — dropping the columns here would instantly break routing on
 * every node still running the old image. So phase 1 (this migration) only:
 *
 *   1. deletes the dependent BPROMPTMETA children of those rows (their FK to
 *      BPROMPTS has no ON DELETE CASCADE, so this must happen first — see up()),
 *   2. deletes the leftover granular system topic rows, and
 *   3. normalizes any stray BENABLED=0 to 1, so old code (which filters
 *      `enabled = true`) and new code (no filter) see the SAME topic pool
 *      during the rollout window.
 *
 * The columns themselves are dead weight with safe defaults (BKEYWORDS
 * nullable, BENABLED DEFAULT 1) — new code neither reads nor writes them.
 * Phase 2 (the actual DROP COLUMN) ships in a later release, once no node
 * runs an entity that maps the columns.
 */
final class Version20260608000000 extends AbstractMigration
{
    private const GRANULAR_TOPICS = [
        'general-chat',
        'coding',
        'image-generation',
        'video-generation',
        'audio-generation',
    ];

    public function getDescription(): string
    {
        return 'Retire embedding-routing experiment (phase 1): remove granular system topics, normalize BENABLED; column drop deferred to a later release';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::GRANULAR_TOPICS), '?'));

        // Remove dependent BPROMPTMETA rows FIRST. The FK
        // BPROMPTMETA.BPROMPTID -> BPROMPTS.BID (FK_D44C7DE359DEE83D, baseline
        // Version20260417000000) has NO ON DELETE CASCADE, so deleting a parent
        // prompt that still has meta children fails with a 1451 constraint
        // violation. Fresh dev/CI installs had no meta for these system prompts,
        // so this only surfaced on a real deploy (web3). The meta is derived
        // data of the exact prompts we are discarding below, so dropping it is
        // safe and intended. Guarded with hasTable so the migration stays
        // re-runnable on any schema shape.
        if ($schema->hasTable('BPROMPTMETA')) {
            $this->addSql(
                sprintf(
                    'DELETE pm FROM BPROMPTMETA pm JOIN BPROMPTS p ON p.BID = pm.BPROMPTID WHERE p.BOWNERID = 0 AND p.BTOPIC IN (%s)',
                    $placeholders,
                ),
                self::GRANULAR_TOPICS,
            );
        }

        // Remove the leftover granular system topic rows (ownerId=0) so the
        // canonical-only routing pool is restored. User-created prompts are
        // never touched. Deliberately destructive: these rows belong to the
        // retired embedding-routing experiment and are NOT restored by down().
        $this->addSql(
            sprintf('DELETE FROM BPROMPTS WHERE BOWNERID = 0 AND BTOPIC IN (%s)', $placeholders),
            self::GRANULAR_TOPICS,
        );

        // Make old + new code agree on the visible topic pool while both run
        // against this DB: old code still filters `BENABLED = 1`, new code
        // ignores the column. The experiment only ever disabled the granular
        // rows deleted above, so this is a safety net for stray rows. Guarded
        // so the migration stays re-runnable on healed schemas where phase 2
        // already dropped the column (see heal-migrations-baseline.sh in the
        // platform repo).
        $prompts = $schema->hasTable('BPROMPTS') ? $schema->getTable('BPROMPTS') : null;
        if (null !== $prompts && $prompts->hasColumn('BENABLED')) {
            $this->addSql('UPDATE BPROMPTS SET BENABLED = 1 WHERE BENABLED = 0');
        }
    }

    public function down(Schema $schema): void
    {
        $this->warnIf(true, 'No-op: the deleted granular topic rows and previous BENABLED values are not restorable (retired routing experiment; re-creatable only from an old seed/backup). The columns were never dropped by this migration.');
    }
}
