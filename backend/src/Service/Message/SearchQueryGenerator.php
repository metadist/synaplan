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
     * @param string      $userQuestion      The original user question
     * @param int|null    $userId            User ID for model config
     * @param string|null $attachmentContext Content of the file(s) the question
     *                                       refers to (extracted text or a vision
     *                                       identification — see
     *                                       {@see AttachmentSearchContextResolver}).
     *                                       When set, the query is ALWAYS built by
     *                                       the model so deictic references
     *                                       ("what is that", "how much does this
     *                                       cost") resolve against the attachment
     *                                       instead of being searched literally.
     *
     * @return string Optimized search query (or original if generation fails)
     */
    public function generate(string $userQuestion, ?int $userId = null, ?string $attachmentContext = null): string
    {
        $this->logger->info('SearchQueryGenerator: Starting query generation', [
            'user_id' => $userId,
            'question_length' => strlen($userQuestion),
            'has_attachment_context' => null !== $attachmentContext,
        ]);

        // Phase 1c: skip the LLM round-trip when the user's message is already
        // a perfectly good search query. Brave's BM25 doesn't benefit from a
        // model-rewritten paraphrase for short, self-contained questions —
        // and the AI call costs 200-1500 ms before we can even start the
        // search. We only invoke the model when the message is long *and*
        // contains pronouns / context references that need conversation
        // resolution ("what about it", "explain that").
        //
        // With attachment context the short-circuit never applies: the whole
        // point is resolving the question's referent against the file content,
        // which only the model can do.
        if (null === $attachmentContext && !$this->messageNeedsLlmRewrite($userQuestion)) {
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

            return $this->fallbackQuery($userQuestion, $attachmentContext);
        }

        // Get sorting model (reuse sorting model for search query generation)
        $modelId = $this->modelConfigService->getDefaultModel('SORT', $userId);

        if (!$modelId) {
            $this->logger->warning('SearchQueryGenerator: No sorting model configured, using fallback');

            return $this->fallbackQuery($userQuestion, $attachmentContext);
        }

        $provider = $this->modelConfigService->getProviderForModel($modelId);
        $modelName = $this->modelConfigService->getModelName($modelId);

        if (!$provider || !$modelName) {
            $this->logger->warning('SearchQueryGenerator: Model configuration invalid, using fallback');

            return $this->fallbackQuery($userQuestion, $attachmentContext);
        }

        // Build messages array for AI
        $userContent = $userQuestion;
        if (null !== $attachmentContext) {
            // Structured shape the tools:search prompt is trained on (see
            // PromptCatalog::searchQueryPrompt guideline 9): the model must
            // name the file's subject, not echo the question words.
            $question = '' !== trim($userQuestion)
                ? $userQuestion
                : '(no question — search for the subject of the attached file)';
            $userContent = "Question: {$question}\n\nAttached file content (the question refers to this):\n{$attachmentContext}";
        }

        $messages = [
            ['role' => 'system', 'content' => $searchPrompt->getPrompt()],
            ['role' => 'user', 'content' => $userContent],
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

                return $this->fallbackQuery($userQuestion, $attachmentContext);
            }

            // Remove any surrounding quotes
            $searchQuery = trim($searchQuery, '"\'');

            return $searchQuery ?: $this->fallbackExtraction($userQuestion);
        } catch (\App\AI\Exception\ProviderException $e) {
            $this->logger->error('SearchQueryGenerator: AI Provider failed', [
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
            ]);

            return $this->fallbackQuery($userQuestion, $attachmentContext);
        } catch (\Throwable $e) {
            $this->logger->error('SearchQueryGenerator: Query generation failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackQuery($userQuestion, $attachmentContext);
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
        // and definite articles ("der/die/das" in DE, "le/la/les" in FR)
        // would over-trigger and force the LLM rewrite on most short
        // queries, defeating the heuristic. Match only genuinely
        // referential pronouns/demonstratives.
        static $referentialPatterns = [
            '/\b(it|its|that|this|those|these|them|they|he|she|him|her|his|hers)\b/i',
            '/\b(es|ihn|ihm|jene[rs]?|diese[rs]?|dasselbe|derselbe|dieselbe)\b/iu', // German pronouns
            '/\b(lui|leur|cela|ceci|celui|celle|ceux|celles)\b/iu',                // French pronouns
            '/\b(eso|esa|esto|aquel|aquella|aquello)\b/iu',                        // Spanish demonstratives
            '/\b(quello|quella|questo|questa|esso|essa)\b/iu',                     // Italian
        ];

        foreach ($referentialPatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback when the model rewrite is unavailable or failed.
     *
     * Without attachment context the question itself is the best we have.
     * WITH context the question is deictic ("what is that") and searching it
     * literally is the exact bug this pipeline exists to prevent — the first
     * line of the file content (extracted text or vision identification)
     * names the subject and makes a far better keyword query.
     */
    private function fallbackQuery(string $userQuestion, ?string $attachmentContext): string
    {
        if (null !== $attachmentContext && '' !== trim($attachmentContext)) {
            $firstLine = strtok(trim($attachmentContext), "\n") ?: trim($attachmentContext);
            $words = preg_split('/\s+/u', trim($firstLine)) ?: [];

            return implode(' ', array_slice($words, 0, 12));
        }

        return $this->fallbackExtraction($userQuestion);
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
