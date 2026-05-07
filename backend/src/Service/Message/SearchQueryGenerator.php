<?php

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Search Query Generator.
 *
 * Uses AI to generate optimized search queries from user questions.
 * Similar to MessageSorter, but focused on web search optimization.
 *
 * Workflow:
 * 1. Load search query prompt from BPROMPTS (tools:search)
 * 2. Call AI with user question
 * 3. Parse AI response (optimized search query)
 */
final readonly class SearchQueryGenerator
{
    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generate optimized search query from user question.
     *
     * @param string   $userQuestion The original user question
     * @param int|null $userId       User ID for model config
     *
     * @return string Optimized search query (or original if generation fails)
     */
    public function generate(string $userQuestion, ?int $userId = null): string
    {
        $this->logger->info('SearchQueryGenerator: Starting query generation', [
            'user_id' => $userId,
            'question_length' => strlen($userQuestion),
        ]);

        // Phase 1c: skip the LLM round-trip when the user's message is already
        // a perfectly good search query. Brave's BM25 doesn't benefit from a
        // model-rewritten paraphrase for short, self-contained questions —
        // and the AI call costs 200-1500 ms before we can even start the
        // search. We only invoke the model when the message is long *and*
        // contains pronouns / context references that need conversation
        // resolution ("what about it", "explain that").
        if (!$this->messageNeedsLlmRewrite($userQuestion)) {
            $cleaned = $this->fallbackExtraction($userQuestion);

            $this->logger->info('SearchQueryGenerator: Skipped LLM rewrite (heuristic short-circuit)', [
                'original_length' => strlen($userQuestion),
                'cleaned' => $cleaned,
            ]);

            return $cleaned;
        }

        // Get search query prompt
        $searchPrompt = $this->promptRepository->findByTopic('tools:search', 0, 'en');

        if (!$searchPrompt) {
            $this->logger->error('SearchQueryGenerator: Search prompt not found, using original question');

            return $this->fallbackExtraction($userQuestion);
        }

        // Get sorting model (reuse sorting model for search query generation)
        $modelId = $this->modelConfigService->getDefaultModel('SORT', $userId);

        if (!$modelId) {
            $this->logger->warning('SearchQueryGenerator: No sorting model configured, using fallback');

            return $this->fallbackExtraction($userQuestion);
        }

        $provider = $this->modelConfigService->getProviderForModel($modelId);
        $modelName = $this->modelConfigService->getModelName($modelId);

        if (!$provider || !$modelName) {
            $this->logger->warning('SearchQueryGenerator: Model configuration invalid, using fallback');

            return $this->fallbackExtraction($userQuestion);
        }

        // Build messages array for AI
        $messages = [
            ['role' => 'system', 'content' => $searchPrompt->getPrompt()],
            ['role' => 'user', 'content' => $userQuestion],
        ];

        try {
            // Call AI for query generation
            $response = $this->aiFacade->chat($messages, $userId, [
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0.3, // Low temperature for consistent results
                'max_tokens' => 100, // Short response expected
            ]);

            $searchQuery = trim($response['content']);

            $this->recordSearchUsage($userId, $modelId, $response, $userQuestion);

            $this->logger->info('SearchQueryGenerator: Query generated', [
                'provider' => $response['provider'],
                'original' => $userQuestion,
                'generated' => $searchQuery,
            ]);

            // Validate: don't use if response is too long or contains explanations
            if (strlen($searchQuery) > 200 || str_contains($searchQuery, "\n\n")) {
                $this->logger->warning('SearchQueryGenerator: Generated query too long or malformed, using fallback');

                return $this->fallbackExtraction($userQuestion);
            }

            // Remove any surrounding quotes
            $searchQuery = trim($searchQuery, '"\'');

            return $searchQuery ?: $this->fallbackExtraction($userQuestion);
        } catch (\App\AI\Exception\ProviderException $e) {
            $this->logger->error('SearchQueryGenerator: AI Provider failed', [
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
            ]);

            return $this->fallbackExtraction($userQuestion);
        } catch (\Throwable $e) {
            $this->logger->error('SearchQueryGenerator: Query generation failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackExtraction($userQuestion);
        }
    }

    /**
     * Record token usage for the search query generation AI call.
     */
    private function recordSearchUsage(?int $userId, ?int $modelId, array $response, string $userQuestion): void
    {
        if (!$userId) {
            return;
        }

        try {
            $user = $this->em->getRepository(User::class)->find($userId);
            if (!$user) {
                return;
            }

            $this->rateLimitService->recordUsage($user, 'SEARCH_QUERY', [
                'usage' => $response['usage'] ?? [],
                'model_id' => $modelId,
                'provider' => $response['provider'] ?? '',
                'model' => $response['model'] ?? '',
                'input_text' => $userQuestion,
                'response_text' => $response['content'] ?? '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('SearchQueryGenerator: Failed to record search query usage', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Decide whether the message needs an LLM-driven rewrite to make a good
     * Brave search query.
     *
     * Heuristic: the model is only worth the round-trip when the question is
     * either very long (likely needs distillation) or contains conversation-
     * relative pronouns that require context to disambiguate ("explain that",
     * "what about it").
     *
     * Plain factual queries ("strait of hormuz history") work fine as-is.
     */
    private function messageNeedsLlmRewrite(string $userQuestion): bool
    {
        $trimmed = trim($userQuestion);
        if ('' === $trimmed) {
            return false;
        }

        // Long message → likely needs distillation.
        $wordCount = preg_match_all('/\S+/u', $trimmed) ?: 0;
        if ($wordCount > 25) {
            return true;
        }

        // Pronouns / referential expressions that need conversation context.
        // The list is intentionally conservative — common words like "the"
        // would over-trigger.
        static $referentialPatterns = [
            '/\b(it|its|that|this|those|these|them|they|he|she|him|her|his|hers)\b/i',
            '/\b(es|ihn|ihm|ihr|ihrer|der|die|das|jenes|jener)\b/iu', // German pronouns (kept loose)
            '/\b(le|la|les|lui|leur|cela|celui|celle)\b/iu',           // French
            '/\b(eso|esa|este|esta|aquel|aquella)\b/iu',                // Spanish
            '/\b(quello|quella|questo|questa|esso|essa)\b/iu',          // Italian
        ];

        foreach ($referentialPatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback extraction: simple keyword extraction from question.
     */
    private function fallbackExtraction(string $text): string
    {
        // Remove common search command prefixes
        $text = preg_replace('/^\/(search|web|google|find)\s+/i', '', $text);

        // Trim whitespace
        $text = trim($text);

        // Remove surrounding quotes (single or double)
        if (preg_match('/^(["\'])(.+)\1$/', $text, $matches)) {
            $text = $matches[2];
        }

        return $text;
    }
}
