<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Memory\ChatMemoryContextLoader;
use App\Service\Multitask\Plan\Capability;
use App\Service\Prompt\PromptService;
use App\Service\RAG\ChatKnowledgeContextLoader;
use App\Service\Runtime\RuntimeProfile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Prepares a chat turn: the runtime profile, the topic prompt, the knowledge
 * base, and the person's memories, feedback and digests.
 *
 * Buffered turns (email, webhook, MCP) and the streamed web chat load the
 * same context with two historical differences kept as they are: the
 * stream reuses the turn's query embedding for the knowledge search and
 * falls back from a topic folder, and its digest search treats file text as
 * part of the prompt.
 */
final readonly class ChatTurnContextLoader
{
    public function __construct(
        private PromptService $promptService,
        private ChatKnowledgeContextLoader $knowledge,
        private ChatMemoryContextLoader $memory,
        private SlashCommandReplies $slashReplies,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The usage hint a bare argument-taking slash command gets instead of a
     * model answer, or null when the turn is not one.
     *
     * @param array<string, mixed> $classification
     */
    public function slashHint(array $classification): ?string
    {
        return $this->slashReplies->hint($classification);
    }

    /**
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     */
    public function forBufferedTurn(Message $message, array $thread, array $classification, array $options, ?callable $progressCallback): ChatTurn
    {
        // Local PerfTimer keeps the shared helpers (memory + feedback
        // loaders) shape-compatible with the streaming path without
        // forcing every non-streaming caller to construct one.
        $perfTimer = new PerfTimer();

        [$classification, $profile] = self::applyRuntimeProfile($classification, $options);

        $topic = $classification['topic'] ?? 'general';
        $promptData = $this->promptService->getPromptWithMetadata($topic, $message->getUserId(), $classification['language'] ?? 'en');

        $searchResults = $classification['search_results'] ?? null;
        if (array_key_exists('search_results', $classification)) {
            unset($classification['search_results']);
        }

        [$ragGroupKey, $ragLimit, $ragMinScore] = $this->knowledge->settings($profile, $classification, $options);
        $isRagQuery = Capability::RagQuery->value === ($classification['intent'] ?? '');
        $agentScopes = $this->knowledge->assistantScopes($profile, $message->getUserId());
        $searchKnowledge = $this->knowledge->shouldSearch(
            $agentScopes,
            (string) $message->getText(),
            $classification,
            $options,
            $isRagQuery,
        );
        $knowledge = ['context' => '', 'sources' => []];
        if ($searchKnowledge) {
            $knowledge = $this->knowledge->load($message, $topic, $ragGroupKey, $ragLimit, $ragMinScore, $agentScopes, true);
        }

        $turn = new ChatTurn(
            message: $message,
            thread: $thread,
            classification: $classification,
            options: $options,
            profile: $profile,
            promptData: $promptData,
            perfTimer: $perfTimer,
            knowledge: new ChatKnowledge($knowledge['context'], $knowledge['sources'], $searchResults),
        );
        if ($isRagQuery && '' === $turn->knowledge->context) {
            return $turn->withKnowledgeMiss($this->slashReplies->docsNotFound($classification));
        }

        // Issue #615: the non-streaming path (email / generic webhook)
        // used to skip memory loading entirely, so memories never
        // influenced replies for those channels and new memories were
        // never extracted from the conversation. Mirror the streaming
        // path so every channel benefits from the user's stored
        // memories + feedback corrections.
        $user = $this->em->getRepository(User::class)->find($message->getUserId());
        $resolveSharedVector = $this->memory->queryVectorResolver($message, $perfTimer);

        return $this->withPersonalContext($turn, $user, $progressCallback, $resolveSharedVector, false);
    }

    /**
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     */
    public function forStreamedTurn(Message $message, array $thread, array $classification, array $options, ?callable $progressCallback): ChatTurn
    {
        $perfTimer = $options['perf_timer'] ?? null;
        if (!$perfTimer instanceof PerfTimer) {
            $perfTimer = new PerfTimer();
        }

        [$classification, $profile] = self::applyRuntimeProfile($classification, $options);

        // Phase 1b: reuse the prompt bundle MessageProcessor already resolved.
        $topic = $classification['topic'] ?? 'general';
        if (isset($options['resolved_prompt_data']) && is_array($options['resolved_prompt_data'])) {
            $promptData = $options['resolved_prompt_data'];
        } else {
            $perfTimer->start('prompt_chathandler');
            $promptData = $this->promptService->getPromptWithMetadata($topic, $message->getUserId(), $classification['language'] ?? 'en');
            $perfTimer->stop('prompt_chathandler');
        }

        $this->logger->info('ChatHandler: Loaded prompt metadata', [
            'topic' => $topic,
            'metadata' => $promptData['metadata'] ?? [],
            'user_id' => $message->getUserId(),
        ]);

        // Phase 1a: embed the user query exactly once and reuse the vector
        // across RAG + memory + feedback searches. The embedding HTTP call
        // dominates per-search latency (50-250 ms each) — paying it 4× was
        // the biggest pre-LLM time sink before this change. Lazy, so a turn
        // without text or with memories off never pays for it.
        $resolveSharedVector = $this->memory->queryVectorResolver($message, $perfTimer);

        [$ragGroupKey, $ragLimit, $ragMinScore] = $this->knowledge->settings($profile, $classification, $options);
        $agentScopes = $this->knowledge->assistantScopes($profile, $message->getUserId());
        $isRagQuery = Capability::RagQuery->value === ($classification['intent'] ?? '');
        $searchKnowledge = $this->knowledge->shouldSearch(
            $agentScopes,
            (string) $message->getText(),
            $classification,
            $options,
            $isRagQuery,
        );
        $knowledge = $this->knowledge->loadForStream(
            $message,
            $topic,
            $ragGroupKey,
            $ragLimit,
            $ragMinScore,
            $agentScopes,
            $searchKnowledge,
            $isRagQuery,
            $resolveSharedVector,
            $perfTimer,
            $progressCallback,
        );

        $turn = new ChatTurn(
            message: $message,
            thread: $thread,
            classification: $classification,
            options: $options,
            profile: $profile,
            promptData: $promptData,
            perfTimer: $perfTimer,
            knowledge: new ChatKnowledge($knowledge['context'], $knowledge['sources']),
        );
        if ($isRagQuery && '' === $turn->knowledge->context) {
            return $turn->withKnowledgeMiss($this->slashReplies->docsNotFound($classification));
        }

        $user = $this->em->getRepository(User::class)->find($message->getUserId());

        return $this->withPersonalContext($turn, $user, $progressCallback, $resolveSharedVector, true);
    }

    /**
     * Pick up the RuntimeProfile the classifier resolved (if any) and let it
     * own the prompt identity. Absent profile ⇒ byte-identical classification.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{0: array<string, mixed>, 1: RuntimeProfile|null}
     */
    private static function applyRuntimeProfile(array $classification, array $options): array
    {
        $profile = $options['runtime_profile'] ?? $classification['runtime_profile'] ?? null;
        if (!$profile instanceof RuntimeProfile) {
            return [$classification, null];
        }

        if ('' !== $profile->promptTopic) {
            $classification['topic'] = $profile->promptTopic;
        }
        if (null !== $profile->promptId) {
            $classification['prompt_id'] = $profile->promptId;
        }

        return [$classification, $profile];
    }

    /**
     * Memories, feedback and digests, in that order: each search reuses the
     * vector the previous one embedded.
     *
     * @param \Closure(): ?array<int, float> $resolveSharedVector
     */
    private function withPersonalContext(
        ChatTurn $turn,
        ?User $user,
        ?callable $progressCallback,
        \Closure $resolveSharedVector,
        bool $promptIncludesFileText,
    ): ChatTurn {
        $message = $turn->message;
        $perfTimer = $turn->perfTimer;
        $resolveMemoryVector = $this->memory->memoryVectorResolver($message, $resolveSharedVector, $perfTimer);

        $memories = $this->memory->loadMemories($message, $user, $turn->options, $turn->classification, $progressCallback, $resolveMemoryVector, $perfTimer);
        $feedback = $this->memory->loadFeedback($message, $user, $turn->options, $turn->classification, $progressCallback, $resolveMemoryVector, $perfTimer);
        $digests = $this->memory->loadDigests($message, $turn->thread, $user, $turn->options, $turn->classification, $progressCallback, $resolveMemoryVector, $perfTimer, promptIncludesFileText: $promptIncludesFileText);

        return $turn->withPersonalContext(new ChatPersonalContext($user, $memories, $feedback, $digests));
    }
}
