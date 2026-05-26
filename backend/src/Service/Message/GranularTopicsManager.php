<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages the BENABLED flag on the granular routing topics that are
 * aliases of canonical legacy topics (see TopicAliasResolver::TOPIC_ALIASES,
 * e.g. `general-chat` → `general`, `image-generation` → `mediamaker`).
 *
 * Why this exists:
 *   - The granular topics only make sense when Synapse Routing v2 (embedding
 *     tier) is in use; the legacy AI sorter sees them as near-duplicates of
 *     the canonical topics and produces brittle picks (e.g. "general" vs
 *     "general-chat"). The system therefore ships with granular topics
 *     DISABLED in PromptCatalog so the AI sorter only sees canonical names.
 *   - When an admin enables Synapse v2 (or wants the granular taxonomy
 *     for any other reason) they flip the `QDRANT_SEARCH.GRANULAR_TOPICS_ENABLED`
 *     BCONFIG row via the admin UI; `SystemConfigService::setValue()` then
 *     calls into here to flip the matching BPROMPTS rows in lock-step.
 *
 * The operation is idempotent: rows already in the target state are skipped,
 * so calling `applyState(false)` twice in a row only writes on the first
 * call. Owner-scoped to BOWNERID=0 because the catalog only seeds system
 * defaults; user-created prompts that happen to share a granular topic name
 * are left untouched.
 */
final readonly class GranularTopicsManager
{
    /**
     * BCONFIG coordinates of the admin toggle that controls the granular
     * routing aliases. Owned here (not on MessageSorter or
     * SystemConfigService) because the manager is the conceptual home of
     * the granular-topic state — every reader and writer in the codebase
     * should refer to these constants instead of inlining the strings.
     */
    public const CONFIG_GROUP = 'QDRANT_SEARCH';
    public const CONFIG_KEY = 'GRANULAR_TOPICS_ENABLED';

    public function __construct(
        private TopicAliasResolver $topicAliasResolver,
        private PromptRepository $promptRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the current admin intent from BCONFIG using the same truthy
     * parser as `MessageClassifier::isSynapseEnabled()`. Absent row → false.
     */
    public static function resolveToggleState(ConfigRepository $configRepository): bool
    {
        $raw = $configRepository->getValue(0, self::CONFIG_GROUP, self::CONFIG_KEY);

        if (null === $raw) {
            return false;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Set BENABLED to the requested state on every system-owned
     * (BOWNERID = 0) prompt whose topic is a known granular alias.
     *
     * Returns the list of topics that were actually flipped and the
     * list that were already in the target state — useful for admin
     * UI confirmation messages and for tests.
     *
     * @return array{flipped: list<string>, unchanged: list<string>, missing: list<string>}
     */
    public function applyState(bool $enabled): array
    {
        $aliasTopics = array_keys($this->topicAliasResolver->getAliasMap());

        $flipped = [];
        $unchanged = [];
        $missing = [];

        foreach ($aliasTopics as $topic) {
            $prompts = $this->promptRepository->findAllByTopicAndOwner($topic, 0);

            if ([] === $prompts) {
                // Catalog row not seeded yet (fresh install before app:seed)
                // — nothing to flip. Not an error; the next seed will create
                // the row with the catalog's own enabled flag.
                $missing[] = $topic;
                continue;
            }

            $anyChanged = false;
            foreach ($prompts as $prompt) {
                if ($prompt->isEnabled() === $enabled) {
                    continue;
                }
                $prompt->setEnabled($enabled);
                $this->entityManager->persist($prompt);
                $anyChanged = true;
            }

            if ($anyChanged) {
                $flipped[] = $topic;
            } else {
                $unchanged[] = $topic;
            }
        }

        if ([] !== $flipped) {
            $this->entityManager->flush();
        }

        $this->logger->info('GranularTopicsManager: applied state', [
            'enabled' => $enabled,
            'flipped' => $flipped,
            'unchanged' => $unchanged,
            'missing' => $missing,
        ]);

        return [
            'flipped' => $flipped,
            'unchanged' => $unchanged,
            'missing' => $missing,
        ];
    }
}
