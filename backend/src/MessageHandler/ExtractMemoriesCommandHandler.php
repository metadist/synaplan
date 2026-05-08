<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Message;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\Service\MemoryExtractionService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for {@see ExtractMemoriesCommand}.
 *
 * Phase 2a: runs on the messenger worker so the user's HTTP stream can
 * close immediately after the answer text is delivered. Persists newly
 * extracted memories via {@see UserMemoryService}; suggested deletions are
 * NOT auto-applied (the original ChatHandler behaviour was to surface them
 * as a UI suggestion, kept here as a structured log entry that the
 * notifications poll endpoint can pick up via {@see Message::getMeta()}).
 *
 * Errors are logged and re-thrown so the messenger retry strategy
 * (max 3 attempts with exponential backoff, see `messenger.yaml`) can
 * recover from transient Qdrant or AI provider blips.
 */
#[AsMessageHandler]
final readonly class ExtractMemoriesCommandHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MemoryExtractionService $memoryExtractionService,
        private UserMemoryService $memoryService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExtractMemoriesCommand $command): void
    {
        $messageId = $command->getMessageId();
        $userId = $command->getUserId();

        $message = $this->em->getRepository(Message::class)->find($messageId);
        if (!$message) {
            $this->logger->warning('ExtractMemoriesCommand: source message not found, skipping', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user) {
            $this->logger->warning('ExtractMemoriesCommand: user not found, skipping', [
                'user_id' => $userId,
                'message_id' => $messageId,
            ]);

            return;
        }

        if (!$user->isMemoriesEnabled()) {
            $this->logger->debug('ExtractMemoriesCommand: memories disabled by user, skipping', [
                'user_id' => $userId,
                'message_id' => $messageId,
            ]);

            return;
        }

        // Build the enhanced thread the same way the inline path used to: thread + assistant response.
        $enhancedThread = $command->getThreadSnapshot();
        $aiResponse = $command->getAiResponse();
        if ('' !== $aiResponse) {
            $enhancedThread[] = ['role' => 'assistant', 'content' => $aiResponse];
        }

        // Load the user's full memory set so the AI can emit `update`
        // actions for existing keys instead of blindly creating duplicates
        // (issue #879). The relevant-memories list passed by the dispatcher
        // is only the small RAG-style subset that was in scope for the
        // assistant's reply — it routinely misses the matching memory when
        // the new user message moves topics (e.g. "ich heiße Furkan" right
        // after a coding question). Merging in the full list, capped at a
        // sane budget, gives the extractor a stable view of what already
        // exists. The cap keeps prompt growth bounded for users with
        // hundreds of memories.
        $existingMemories = $this->buildExistingMemoryContext(
            $userId,
            $command->getRelevantMemories(),
        );

        $this->logger->info('ExtractMemoriesCommand: starting extraction', [
            'message_id' => $messageId,
            'user_id' => $userId,
            'thread_length' => count($enhancedThread),
            'relevant_memories' => count($command->getRelevantMemories()),
            'existing_memories_passed' => count($existingMemories),
        ]);

        try {
            $memoryActions = $this->memoryExtractionService->analyzeAndExtract(
                $message,
                $enhancedThread,
                $existingMemories,
            );
        } catch (\Throwable $e) {
            $this->logger->error('ExtractMemoriesCommand: extraction LLM call failed', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // let messenger retry
        }

        if (empty($memoryActions)) {
            $this->logger->info('ExtractMemoriesCommand: no memories extracted', [
                'message_id' => $messageId,
            ]);
            $this->writeOutcomeMeta($message, status: 'empty', savedMemories: [], deleteSuggestions: []);

            return;
        }

        $savedMemories = [];
        $deleteSuggestions = [];
        $skippedDuplicates = 0;

        // Index existing memories by (category, key) for backend-side dedup.
        // Even when the AI prompt is given the full memory set, providers
        // routinely emit `create` for an unchanged fact — so the worker
        // enforces the dedup contract itself rather than trusting model
        // output. Layered with the prompt-side hint, this turns the
        // double-write race described in #879 into a no-op.
        //
        // `byCatKey` maps `(cat, key)` to the *first* matching memory
        // (used for the create→update promotion below). The full list of
        // existing memories is kept around for the post-loop singleton
        // collapse — that step needs to see siblings, not just one.
        [$byCatKeyValue, $byCatKey] = $this->indexExistingByCatKey($existingMemories);

        /**
         * IDs of memories touched (create/update) in this extraction
         * batch, used by the singleton-collapse post-pass.
         *
         * @var array<int, true>
         */
        $touchedIds = [];

        /**
         * Singleton `(cat, key)` hashes touched in this batch, mapped to
         * the saved memory representing the new current value. Used to
         * prune stray duplicates from the user's memory list.
         *
         * @var array<string, array<string, mixed>>
         */
        $touchedSingletons = [];

        foreach ($memoryActions as $action) {
            try {
                $kind = $action['action'] ?? 'create';

                if ('create' === $kind) {
                    $category = (string) ($action['category'] ?? 'personal');
                    $key = (string) ($action['key'] ?? '');
                    $value = (string) ($action['value'] ?? '');

                    $catKeyValueHash = $this->normalizeForDedup($category, $key, $value);
                    if (isset($byCatKeyValue[$catKeyValueHash])) {
                        // Exact same fact already stored — skip silently.
                        // This is the literal "Furkan was already saved as
                        // Furkan" case from the bug report.
                        ++$skippedDuplicates;
                        $this->logger->info('ExtractMemoriesCommand: dropped duplicate create (exact match)', [
                            'message_id' => $messageId,
                            'category' => $category,
                            'key' => $key,
                        ]);
                        continue;
                    }

                    $catKeyHash = $this->normalizeForDedup($category, $key);
                    if (isset($byCatKey[$catKeyHash]) && $this->isSingletonKey($key)) {
                        // Singleton keys (name, age, location, …) describe a
                        // single current value per user. The AI failed to
                        // emit `update` so we promote the create to an
                        // update of the existing entry. Multi-value keys
                        // like `diet` or `hobby` are intentionally NOT
                        // matched here — see {@see isSingletonKey()}.
                        $existingId = (int) $byCatKey[$catKeyHash]['id'];
                        $memory = $this->memoryService->updateMemory(
                            $existingId,
                            $user,
                            $value,
                            'ai_edited',
                            $message->getId(),
                        );
                        $memoryArray = $memory->toArray();
                        $savedMemories[] = $memoryArray;
                        $byCatKey[$catKeyHash] = $memoryArray;
                        $byCatKeyValue[$this->normalizeForDedup($category, $key, $value)] = $memoryArray;
                        $touchedIds[$existingId] = true;
                        $touchedSingletons[$catKeyHash] = $memoryArray;
                        $this->logger->info('ExtractMemoriesCommand: promoted create to update for singleton key', [
                            'message_id' => $messageId,
                            'category' => $category,
                            'key' => $key,
                            'memory_id' => $existingId,
                        ]);
                        continue;
                    }

                    $memory = $this->memoryService->createMemory(
                        $user,
                        $category,
                        $key,
                        $value,
                        'auto_detected',
                        $message->getId(),
                    );
                    $memoryArray = $memory->toArray();
                    $savedMemories[] = $memoryArray;
                    // Track newly-created memory in our local index so a
                    // second action in the same batch with the same value
                    // is also deduped.
                    $byCatKeyValue[$catKeyValueHash] = $memoryArray;
                    $byCatKey[$catKeyHash] = $memoryArray;
                    if (isset($memoryArray['id'])) {
                        $touchedIds[(int) $memoryArray['id']] = true;
                    }
                    if ($this->isSingletonKey($key)) {
                        $touchedSingletons[$catKeyHash] = $memoryArray;
                    }
                } elseif ('update' === $kind && isset($action['memory_id'])) {
                    $memory = $this->memoryService->updateMemory(
                        (int) $action['memory_id'],
                        $user,
                        $action['value'],
                        'ai_edited',
                        $message->getId(),
                    );
                    $memoryArray = $memory->toArray();
                    $savedMemories[] = $memoryArray;
                    $touchedIds[(int) $action['memory_id']] = true;

                    // The AI may pick the wrong target when several
                    // singleton-key memories exist (the screenshot in
                    // #879 had `name=Ralf` AND `name=<old>` and the AI
                    // updated the wrong one). Mark the singleton hash
                    // as touched here too so the post-loop collapse
                    // pass prunes the leftover stale siblings.
                    $touchedKey = (string) ($memoryArray['key'] ?? '');
                    $touchedCategory = (string) ($memoryArray['category'] ?? '');
                    if ('' !== $touchedKey && '' !== $touchedCategory && $this->isSingletonKey($touchedKey)) {
                        $touchedSingletons[$this->normalizeForDedup($touchedCategory, $touchedKey)] = $memoryArray;
                    }
                } elseif ('delete' === $kind && isset($action['memory_id'])) {
                    $existing = $this->memoryService->getMemoryById((int) $action['memory_id'], $user);
                    if ($existing) {
                        $deleteSuggestions[] = $existing->toArray();
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ExtractMemoriesCommand: failed to persist a single memory action', [
                    'message_id' => $messageId,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
                // Continue with the next action — partial results are better than none.
            }
        }

        if ($skippedDuplicates > 0) {
            $this->logger->info('ExtractMemoriesCommand: skipped duplicate creates', [
                'message_id' => $messageId,
                'count' => $skippedDuplicates,
            ]);
        }

        // Singleton-key collapse pass.
        //
        // After the action loop, for each singleton `(category, key)` we
        // touched (e.g. the user just stated their `name=Hannes`), find
        // any leftover memories with the SAME `(category, key)` that we
        // did NOT touch — those are stale by definition for singleton
        // keys, since the user only has one current name / age /
        // location / job at a time. Auto-delete them so the user never
        // has to clean up the UI manually after the AI mis-targets an
        // update (issue #879 follow-up: the screenshot showed `Ralf`
        // surviving alongside the freshly-updated `Hannes`).
        //
        // Multi-valued keys (diet, hobby, skill, …) are excluded by
        // `isSingletonKey()` so distinct facts under the same key are
        // never collapsed.
        $this->collapseSingletonDuplicates(
            $user,
            $existingMemories,
            $touchedSingletons,
            $touchedIds,
            $messageId,
        );

        $this->writeOutcomeMeta(
            $message,
            status: empty($savedMemories) && empty($deleteSuggestions) ? 'empty' : 'complete',
            savedMemories: $savedMemories,
            deleteSuggestions: $deleteSuggestions,
        );

        $this->logger->info('ExtractMemoriesCommand: extraction complete', [
            'message_id' => $messageId,
            'saved' => count($savedMemories),
            'delete_suggestions' => count($deleteSuggestions),
        ]);
    }

    /**
     * Write extraction outcome to BOTH the incoming user message AND the
     * outgoing assistant message (when present), so the frontend's poll
     * endpoint (Phase 2c) finds it whether it polls the user-side or
     * assistant-side message ID.
     *
     * The frontend currently polls the assistant message ID it received in
     * the SSE `complete` event (StreamController emits
     * `outgoingMessage->getId()`), but the worker only sees the user-side
     * incoming message that was queued. We pair them up via trackingId +
     * userId + direction='OUT' so the meta lands where the poll will look
     * for it. Writing to both is idempotent and protects against future
     * frontend changes.
     *
     * Stores a single JSON-encoded BMESSAGEMETA row keyed by
     * `extracted_memories` — small, idempotent, no schema change.
     *
     * @param array<int, array<string, mixed>> $savedMemories
     * @param array<int, array<string, mixed>> $deleteSuggestions
     */
    private function writeOutcomeMeta(
        Message $message,
        string $status,
        array $savedMemories,
        array $deleteSuggestions,
    ): void {
        try {
            $payload = json_encode([
                'status' => $status,
                'completed_at' => time(),
                'saved' => $savedMemories,
                'delete_suggestions' => $deleteSuggestions,
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

            if (false === $payload) {
                $this->logger->warning('ExtractMemoriesCommand: failed to JSON-encode outcome', [
                    'message_id' => $message->getId(),
                ]);

                return;
            }

            // Always write to the incoming user message (for completeness +
            // future-proofing).
            $message->setMeta('extracted_memories', $payload);

            // Find the paired outgoing assistant message (same tracking_id +
            // user_id, direction = OUT) and write the same payload there
            // too. The poll endpoint's frontend caller uses the assistant
            // message ID it got from the SSE `complete` event, so without
            // this the poll would always return `pending`.
            $outgoing = $this->em->getRepository(Message::class)->findOneBy([
                'userId' => $message->getUserId(),
                'trackingId' => $message->getTrackingId(),
                'direction' => 'OUT',
            ]);

            if ($outgoing) {
                $outgoing->setMeta('extracted_memories', $payload);
            } else {
                $this->logger->debug('ExtractMemoriesCommand: no outgoing message found for tracking_id, wrote to incoming only', [
                    'message_id' => $message->getId(),
                    'tracking_id' => $message->getTrackingId(),
                ]);
            }

            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('ExtractMemoriesCommand: failed to persist outcome meta', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-collapse stale duplicates of singleton-key memories.
     *
     * For every singleton `(category, key)` pair we touched in this
     * extraction batch, find every OTHER memory in the user's pre-batch
     * snapshot that shares the same hash and delete it from Qdrant.
     *
     * Why auto-delete instead of suggesting deletion: singleton keys
     * (`name`, `age`, `location`, `job`, …) describe a single CURRENT
     * value per user. The fresh value the user just stated supersedes
     * the older entries by definition — keeping them around is the bug
     * the user reported in the #879 follow-up screenshot, where
     * `name=Ralf` lingered next to a freshly-updated `name=Hannes`.
     *
     * Multi-valued keys (`diet`, `hobby`, `skill`, …) never reach this
     * pass because they're filtered upstream by `isSingletonKey()`.
     *
     * @param array<int, array<string, mixed>>    $existingMemories  user's memory snapshot loaded before the action loop
     * @param array<string, array<string, mixed>> $touchedSingletons singleton (cat,key) hashes touched in this batch
     * @param array<int, true>                    $touchedIds        memory IDs created/updated in this batch
     */
    private function collapseSingletonDuplicates(
        User $user,
        array $existingMemories,
        array $touchedSingletons,
        array $touchedIds,
        int $messageId,
    ): void {
        if (empty($touchedSingletons)) {
            return;
        }

        $deleted = 0;
        $failed = 0;

        foreach ($existingMemories as $candidate) {
            $candidateId = isset($candidate['id']) ? (int) $candidate['id'] : 0;
            if (0 === $candidateId) {
                continue;
            }
            if (isset($touchedIds[$candidateId])) {
                // We just created/updated this one — keep it.
                continue;
            }

            $hash = $this->memoryDedupeKey($candidate);
            if (null === $hash || !isset($touchedSingletons[$hash])) {
                continue;
            }

            try {
                $this->memoryService->deleteMemory($candidateId, $user);
                ++$deleted;
                $this->logger->info('ExtractMemoriesCommand: auto-collapsed stale singleton duplicate', [
                    'message_id' => $messageId,
                    'memory_id' => $candidateId,
                    'category' => $candidate['category'] ?? null,
                    'key' => $candidate['key'] ?? null,
                    'kept_id' => $touchedSingletons[$hash]['id'] ?? null,
                ]);
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->warning('ExtractMemoriesCommand: failed to collapse singleton duplicate', [
                    'message_id' => $messageId,
                    'memory_id' => $candidateId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($deleted > 0 || $failed > 0) {
            $this->logger->info('ExtractMemoriesCommand: singleton collapse complete', [
                'message_id' => $messageId,
                'deleted' => $deleted,
                'failed' => $failed,
            ]);
        }
    }

    /**
     * Soft cap on how many existing memories we forward to the AI prompt.
     *
     * Large enough that virtually all real users fit within it (the per-user
     * hard limit is 500 and 90%+ of users have <50). Small enough that the
     * extraction prompt stays under the budget that fast extraction models
     * (Groq gpt-oss-120b at the default) can chew through in ~200ms.
     */
    private const EXISTING_MEMORY_BUDGET = 80;

    /**
     * Build the existing-memory snapshot the extractor LLM gets to see.
     *
     * Merges:
     *   1. the relevant memories the dispatcher pre-loaded for the assistant
     *      reply (highest signal — semantically tied to the new turn);
     *   2. the user's full memory list, capped at {@see EXISTING_MEMORY_BUDGET}
     *      and sorted by recency, so the AI also sees keys it hasn't queried
     *      for in a while (the `name=Furkan` from message #1 when the new
     *      message is a name update on message #5).
     *
     * Deduplication is by memory ID, not by `(category, key)` — siblings
     * with the same key (e.g. multiple `diet` facts, or two pre-existing
     * `name` entries that need to be collapsed by the action loop) MUST
     * all be preserved here. Returns plain arrays (not DTOs) because the
     * extraction service consumes them positionally.
     *
     * @param array<int, array<string, mixed>> $relevantMemories
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildExistingMemoryContext(int $userId, array $relevantMemories): array
    {
        /** @var array<int, array<string, mixed>> $byId */
        $byId = [];
        $unkeyed = [];

        foreach ($relevantMemories as $memory) {
            $id = isset($memory['id']) ? (int) $memory['id'] : 0;
            if ($id > 0) {
                $byId[$id] = $memory;
            } else {
                $unkeyed[] = $memory;
            }
        }

        try {
            $userMemories = $this->memoryService->getUserMemories($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('ExtractMemoriesCommand: failed to load existing memories, continuing with relevant subset only', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return array_merge(array_values($byId), $unkeyed);
        }

        foreach ($userMemories as $dto) {
            $arr = $dto->toArray();
            $id = isset($arr['id']) ? (int) $arr['id'] : 0;
            if ($id <= 0) {
                continue;
            }
            // Don't overwrite a relevant-memory entry — those have higher
            // signal value (came with semantic-search scores already).
            if (!isset($byId[$id])) {
                $byId[$id] = $arr;
            }

            if (count($byId) >= self::EXISTING_MEMORY_BUDGET) {
                break;
            }
        }

        return array_merge(array_values($byId), $unkeyed);
    }

    /**
     * @param array<string, mixed> $memory
     */
    private function memoryDedupeKey(array $memory): ?string
    {
        $category = isset($memory['category']) ? (string) $memory['category'] : null;
        $key = isset($memory['key']) ? (string) $memory['key'] : null;
        if (null === $category || '' === $category || null === $key || '' === $key) {
            return null;
        }

        return $this->normalizeForDedup($category, $key);
    }

    /**
     * Build (cat,key) and (cat,key,value) hash maps over the existing
     * memory snapshot so the action loop can dedupe in O(1).
     *
     * @param array<int, array<string, mixed>> $existingMemories
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     *                                                                                               [`$byCatKeyValue` (exact match key), `$byCatKey` (key-only match)]
     */
    private function indexExistingByCatKey(array $existingMemories): array
    {
        $byCatKeyValue = [];
        $byCatKey = [];

        foreach ($existingMemories as $memory) {
            $category = (string) ($memory['category'] ?? '');
            $key = (string) ($memory['key'] ?? '');
            $value = (string) ($memory['value'] ?? '');
            if ('' === $category || '' === $key) {
                continue;
            }

            $byCatKeyValue[$this->normalizeForDedup($category, $key, $value)] = $memory;
            $byCatKey[$this->normalizeForDedup($category, $key)] = $memory;
        }

        return [$byCatKeyValue, $byCatKey];
    }

    /**
     * Build a stable comparison hash for memory dedup.
     *
     * Casefolds and trims so e.g. "Furkan", "furkan ", "FURKAN" collapse to
     * the same bucket. Whitespace inside the value is collapsed because AI
     * extractions often differ only in formatting ("Furkan" vs "Furkan ").
     */
    private function normalizeForDedup(string $category, string $key, ?string $value = null): string
    {
        $norm = static function (string $s): string {
            $s = mb_strtolower(trim($s));

            return (string) preg_replace('/\s+/', ' ', $s);
        };

        if (null === $value) {
            return $norm($category).'|'.$norm($key);
        }

        return $norm($category).'|'.$norm($key).'|'.$norm($value);
    }

    /**
     * Keys that semantically allow only one current value per user.
     *
     * For these we promote a stray `create` (with a different value than
     * the one already stored) into an `update` of the existing memory.
     * Multi-valued keys like `diet`, `hobby`, `skill`, `interest` are
     * intentionally excluded — multiple distinct facts under the same key
     * are perfectly valid there ("eats halal" + "low-calorie" from the
     * existing test). When in doubt we DON'T treat the key as a singleton:
     * a duplicate is annoying, but losing a legitimate distinct fact via a
     * silent overwrite is much worse.
     */
    private function isSingletonKey(string $key): bool
    {
        $singletons = [
            'name',
            'first_name',
            'last_name',
            'full_name',
            'age',
            'birthday',
            'birth_date',
            'date_of_birth',
            'location',
            'city',
            'country',
            'address',
            'gender',
            'pronouns',
            'job',
            'job_title',
            'occupation',
            'profession',
            'role',
            'company',
            'employer',
            'email',
            'phone',
            'phone_number',
            'website',
            'native_language',
            'preferred_language',
            'timezone',
            'marital_status',
            'ui_theme',
        ];

        return in_array(mb_strtolower(trim($key)), $singletons, true);
    }
}
