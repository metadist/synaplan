<?php

declare(strict_types=1);

namespace App\Seed;

use App\Prompt\PromptCatalog;
use App\Repository\ConfigRepository;
use App\Service\Message\GranularTopicsManager;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for system prompts (BPROMPTS where BOWNERID=0).
 *
 * Wraps PromptCatalog::seed(), which itself uses INSERT/UPDATE on
 * (ownerId, topic, language). User-created prompts (ownerId>0) are never touched.
 *
 * After the catalog write, re-converges the BENABLED flag on the granular
 * routing aliases to whatever the admin chose via the
 * `GRANULAR_TOPICS_ENABLED` BCONFIG toggle. Without this step, every
 * `app:seed` run (deploy, container restart, manual re-seed) would
 * silently overwrite an admin-enabled toggle's effect on BPROMPTS while
 * leaving the BCONFIG row alone, producing a split-brain state where the
 * admin UI shows ON but the prompt rows are disabled.
 */
final readonly class PromptSeeder
{
    public function __construct(
        private Connection $connection,
        private ConfigRepository $configRepository,
        private GranularTopicsManager $granularTopicsManager,
    ) {
    }

    public function seed(): SeedResult
    {
        $result = PromptCatalog::seed($this->connection);

        $this->convergeGranularToggleState();

        return new SeedResult(
            'prompts',
            inserted: count($result['inserted']),
            updated: count($result['updated']),
        );
    }

    /**
     * Re-apply the admin toggle state so BPROMPTS.BENABLED matches BCONFIG
     * after the catalog seed. The manager is idempotent — when the rows
     * already match (the common case on a fresh install with the toggle
     * absent), this is a no-op.
     */
    private function convergeGranularToggleState(): void
    {
        $enabled = GranularTopicsManager::resolveToggleState($this->configRepository);
        $this->granularTopicsManager->applyState($enabled);
    }
}
