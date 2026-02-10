<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\DTO\UserMemoryDTO;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\RAG\VectorSearchService;
use App\Service\Search\BraveSearchService;
use Psr\Log\LoggerInterface;

final readonly class FeedbackExampleService
{
    private const MAX_SUMMARY_LENGTH = 500;
    private const MAX_INPUT_LENGTH = 2000;
    private const NAMESPACE_FALSE_POSITIVE = 'feedback_false_positive';
    private const NAMESPACE_POSITIVE = 'feedback_positive';

    private const MAX_RESEARCH_SOURCES = 6;
    private const MIN_RESEARCH_SCORE = 0.35;
    private const MIN_MEMORY_RESEARCH_SCORE = 0.55;
    private const MAX_WEB_RESULTS = 5;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private UserMemoryService $memoryService,
        private VectorSearchService $vectorSearchService,
        private BraveSearchService $braveSearchService,
        private PromptRepository $promptRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List all feedback examples for a user.
     *
     * @param string $type Filter: 'false_positive', 'positive', or 'all'
     *
     * @return array<array{id: int, type: string, value: string, messageId: ?int, created: int, updated: int}>
     */
    public function listFeedbacks(User $user, string $type = 'all'): array
    {
        if (!$this->memoryService->isAvailable()) {
            // Return empty array if service is unavailable (graceful degradation)
            $this->logger->warning('FeedbackExampleService: Memory service (Vector DB) unavailable, returning empty list');

            return [];
        }

        $feedbacks = [];

        try {
            // Load false positives
            if ('all' === $type || 'false_positive' === $type) {
                $falsePositives = $this->memoryService->scrollMemories(
                    $user->getId(),
                    'feedback_negative',
                    1000,
                    self::NAMESPACE_FALSE_POSITIVE
                );
                foreach ($falsePositives as $fp) {
                    $feedbacks[] = $this->mapMemoryToFeedback($fp, 'false_positive');
                }
            }

            // Load positive examples
            if ('all' === $type || 'positive' === $type) {
                $positives = $this->memoryService->scrollMemories(
                    $user->getId(),
                    'feedback_positive',
                    1000,
                    self::NAMESPACE_POSITIVE
                );
                foreach ($positives as $p) {
                    $feedbacks[] = $this->mapMemoryToFeedback($p, 'positive');
                }
            }

            // Filter out empty values and sort by created desc
            $feedbacks = array_filter($feedbacks, fn ($f) => !empty($f['value']));
            usort($feedbacks, fn ($a, $b) => $b['created'] <=> $a['created']);
        } catch (\Throwable $e) {
            $this->logger->warning('FeedbackExampleService: Failed to load feedbacks', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            // Return empty array on error (graceful degradation)
            return [];
        }

        return $feedbacks;
    }

    /**
     * Map a memory array from Qdrant to a feedback array.
     * Handles different data structures that may come from the service.
     */
    private function mapMemoryToFeedback(array $memory, string $type): array
    {
        // Try different field names that the Qdrant service might use
        $id = $memory['id'] ?? $memory['point_id'] ?? 0;
        // Extract numeric ID from point_id format (e.g., "mem_1_123456" -> 123456)
        if (is_string($id) && str_starts_with($id, 'mem_')) {
            $parts = explode('_', $id);
            $id = (int) end($parts);
        }

        // Value can be in different fields
        $value = $memory['value']
            ?? $memory['text']
            ?? $memory['content']
            ?? ($memory['payload']['value'] ?? '')
            ?? ($memory['payload']['text'] ?? '')
            ?? '';

        $messageId = $memory['messageId']
            ?? $memory['message_id']
            ?? ($memory['payload']['message_id'] ?? null)
            ?? null;

        $created = $memory['created']
            ?? $memory['created_at']
            ?? ($memory['payload']['created'] ?? 0)
            ?? 0;

        $updated = $memory['updated']
            ?? $memory['updated_at']
            ?? ($memory['payload']['updated'] ?? 0)
            ?? 0;

        return [
            'id' => (int) $id,
            'type' => $type,
            'value' => (string) $value,
            'messageId' => $messageId ? (int) $messageId : null,
            'created' => (int) $created,
            'updated' => (int) $updated,
        ];
    }

    /**
     * Update a feedback example.
     */
    public function updateFeedback(User $user, int $id, string $value): array
    {
        if (!$this->memoryService->isAvailable()) {
            throw new \RuntimeException('Memory service unavailable');
        }

        // Try to find the feedback in either namespace
        $memory = $this->memoryService->getMemoryById($id, $user);

        if (!$memory) {
            throw new \InvalidArgumentException('Feedback not found');
        }

        // Determine the type based on namespace/category
        $type = 'feedback_negative' === $memory->category ? 'false_positive' : 'positive';
        $namespace = 'false_positive' === $type ? self::NAMESPACE_FALSE_POSITIVE : self::NAMESPACE_POSITIVE;

        // Update the memory
        $updated = $this->memoryService->updateMemory(
            $id,
            $user,
            $value,
            'user_edited',
            $memory->messageId,
            null, // key stays the same
            null, // category stays the same
            $namespace
        );

        return [
            'id' => $updated->id,
            'type' => $type,
            'value' => $updated->value,
            'messageId' => $updated->messageId,
            'created' => $updated->created,
            'updated' => $updated->updated,
        ];
    }

    /**
     * Delete a feedback example.
     */
    public function deleteFeedback(User $user, int $id): void
    {
        if (!$this->memoryService->isAvailable()) {
            throw new \RuntimeException('Memory service unavailable');
        }

        // Verify ownership
        $memory = $this->memoryService->getMemoryById($id, $user);

        if (!$memory) {
            throw new \InvalidArgumentException('Feedback not found');
        }

        $namespace = 'feedback_negative' === $memory->category ? self::NAMESPACE_FALSE_POSITIVE : self::NAMESPACE_POSITIVE;

        $this->memoryService->deleteMemory($id, $user, $namespace);
    }

    /**
     * Generate preview options for a false positive in a SINGLE AI call.
     * Returns 2-4 summary + correction options (AI decides how many based on complexity).
     * Uses the dedicated TOOLS model to minimize cost.
     * Fetches related user memories as context so the AI knows which claims come from stored data.
     */
    public function previewFalsePositive(User $user, string $text, ?string $userMessage = null): array
    {
        // Truncate input to save tokens
        $text = mb_substr(trim($text), 0, self::MAX_INPUT_LENGTH);

        $toolsConfig = $this->modelConfigService->getToolsModelConfig();
        $provider = $toolsConfig['provider'];
        $modelName = $toolsConfig['model'];

        $contextPart = '';
        if ($userMessage) {
            $userMessage = mb_substr(trim($userMessage), 0, 500);
            $contextPart = "User question:\n{$userMessage}\n\n";
        }

        // Fetch related memories so the AI knows which claims come from stored user data
        $memoryData = $this->fetchRelatedMemories($user, $text);
        $memoryPart = '';
        if ('' !== $memoryData['text']) {
            $memoryContext = $memoryData['text'];
            $memoryPart = <<<MEMORY

STORED USER MEMORIES (these are actual facts currently saved for this user):
{$memoryContext}

IMPORTANT: If the incorrect AI response was based on one of these stored memories, the classification MUST be "memory". The AI did NOT hallucinate — it used stored data that the user now says is wrong. Correction options should reflect what the user actually wants (e.g., correct value, or leave empty if the memory should simply be deleted).
MEMORY;
        }

        $systemPrompt = <<<'PROMPT'
You analyze incorrect AI responses and generate feedback options. You must produce summary options (what was false), correction options (what is correct), AND a classification in a single JSON response.

Classification rules:
- "memory": The error is about PERSONAL USER FACTS (name, birthday, email, preferences, address, relationships, skills, etc.) OR the AI response was based on a stored user memory that is now incorrect. These should be stored/updated as user memories.
- "feedback": The error is about GENERAL KNOWLEDGE or AI BEHAVIOR (wrong facts about the world, bad formatting, wrong tone, etc.) AND is NOT based on a stored memory. These should be stored as feedback examples.

Content rules:
- Generate 2-4 options for EACH field, depending on complexity:
  - Simple, clear-cut error → 2 options each
  - Ambiguous or multi-faceted error → 3-4 options each
- summaryOptions: Different phrasings of the false claim, ranked from most precise to most general
- correctionOptions: Different phrasings of the correct information
  - If classification is "memory": Format corrections as clean memory values (e.g., "Python, JavaScript" not "The correct skills are Python and JavaScript"). Only provide corrections if there is a meaningful replacement value.
  - If classification is "feedback": Format corrections as complete statements
- Each option must be a single, clear sentence
- ALWAYS respond in the SAME LANGUAGE as the input text
- Output ONLY valid JSON: {"classification":"memory|feedback","summaryOptions":["..."],"correctionOptions":["..."]}
- No markdown, no explanation, no other text
PROMPT;

        $userPrompt = <<<PROMPT
{$contextPart}{$memoryPart}AI response (marked as incorrect by the user):
{$text}

Generate summary and correction options.
PROMPT;

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                null, // No user-specific model - use tools model
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.3,
                ])
            );

            $content = trim((string) ($response['content'] ?? ''));
            $parsed = $this->parseCombinedPreview($content);

            if (null !== $parsed) {
                return [
                    'classification' => $parsed['classification'],
                    'summaryOptions' => array_map(
                        fn (string $opt) => $this->normalizeSummary($opt, $text),
                        $parsed['summaryOptions']
                    ),
                    'correctionOptions' => array_map(
                        fn (string $opt) => $this->normalizeSummary($opt, $text),
                        $parsed['correctionOptions']
                    ),
                    'relatedMemoryIds' => $memoryData['ids'],
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Combined preview generation failed, falling back to separate calls', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: two separate single-option calls
        $summary = $this->summarizeFalsePositive($user, $text, $userMessage);
        $correction = $this->suggestCorrection($user, $text, $userMessage);

        return [
            'classification' => 'feedback',
            'summaryOptions' => [$this->normalizeSummary($summary, $text)],
            'correctionOptions' => [$this->normalizeSummary($correction, $text)],
            'relatedMemoryIds' => $memoryData['ids'],
        ];
    }

    /**
     * Parse the combined preview JSON response from AI.
     *
     * @return array{classification: string, summaryOptions: string[], correctionOptions: string[]}|null
     */
    private function parseCombinedPreview(string $content): ?array
    {
        // Strip markdown code fences
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```\s*$/i', '', $content) ?? $content;
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }

        $summaryOptions = $decoded['summaryOptions'] ?? [];
        $correctionOptions = $decoded['correctionOptions'] ?? [];

        if (!is_array($summaryOptions) || !is_array($correctionOptions)) {
            return null;
        }

        $filterOptions = fn (array $opts): array => array_values(
            array_slice(
                array_filter(array_map('trim', $opts), fn (string $o) => '' !== $o),
                0,
                5
            )
        );

        $summaryOptions = $filterOptions($summaryOptions);
        $correctionOptions = $filterOptions($correctionOptions);

        if ([] === $summaryOptions && [] === $correctionOptions) {
            return null;
        }

        $classification = (string) ($decoded['classification'] ?? 'feedback');
        if (!in_array($classification, ['memory', 'feedback'], true)) {
            $classification = 'feedback';
        }

        return [
            'classification' => $classification,
            'summaryOptions' => $summaryOptions,
            'correctionOptions' => $correctionOptions,
        ];
    }

    public function createFalsePositive(User $user, string $summary, ?int $messageId = null): UserMemoryDTO
    {
        if (!$this->memoryService->isAvailable()) {
            throw new \RuntimeException('Memory service unavailable');
        }

        return $this->memoryService->createMemory(
            $user,
            'feedback_negative',
            'false_positive',
            $summary,
            'user_created',
            $messageId,
            self::NAMESPACE_FALSE_POSITIVE
        );
    }

    public function createPositive(User $user, string $text, ?int $messageId = null): UserMemoryDTO
    {
        if (!$this->memoryService->isAvailable()) {
            throw new \RuntimeException('Memory service unavailable');
        }

        $value = $this->normalizeSummary($text, $text);

        return $this->memoryService->createMemory(
            $user,
            'feedback_positive',
            'positive_example',
            $value,
            'user_created',
            $messageId,
            self::NAMESPACE_POSITIVE
        );
    }

    /**
     * Regenerate a correction based on a false claim and previous incorrect correction.
     * The AI will generate a better, more accurate correction in the same language as the false claim.
     */
    public function regenerateCorrection(User $user, string $falseClaim, string $oldCorrection): string
    {
        $toolsConfig = $this->modelConfigService->getToolsModelConfig();
        $provider = $toolsConfig['provider'];
        $modelName = $toolsConfig['model'];

        $systemPrompt = <<<'PROMPT'
You are a fact-checking assistant. Your task is to provide accurate corrections for false claims.

Rules:
1. ALWAYS respond in the SAME LANGUAGE as the false claim
2. Provide a factually accurate correction
3. Be concise - one or two sentences maximum
4. If a previous correction was provided and it was incorrect, improve upon it
5. Output ONLY the corrected statement, nothing else
PROMPT;

        $userPrompt = "FALSE CLAIM:\n{$falseClaim}";
        if (!empty($oldCorrection)) {
            $userPrompt .= "\n\nPREVIOUS INCORRECT CORRECTION:\n{$oldCorrection}\n\nPlease provide a better, accurate correction.";
        } else {
            $userPrompt .= "\n\nProvide the correct statement.";
        }

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                null,
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.3,
                ])
            );

            $correction = trim((string) ($response['content'] ?? ''));

            return $this->normalizeSummary($correction, $falseClaim);
        } catch (\Throwable $e) {
            $this->logger->warning('Correction regeneration failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function summarizeFalsePositive(User $user, string $text, ?string $userMessage = null): string
    {
        $toolsConfig = $this->modelConfigService->getToolsModelConfig();
        $provider = $toolsConfig['provider'];
        $modelName = $toolsConfig['model'];

        $systemPrompt = $this->getFalsePositivePrompt();

        // Build context with user question if available
        $contextPart = '';
        if ($userMessage) {
            $contextPart = <<<CONTEXT
User question:
{$userMessage}

CONTEXT;
        }

        $userPrompt = <<<PROMPT
{$contextPart}AI response (marked as incorrect by the user):
{$text}

Summarize the false or undesirable claim in one sentence. If the AI response is vague or lacks content, use the user question to understand what topic the AI should have addressed.
IMPORTANT: Respond in the SAME LANGUAGE as the text above. If the text is in German, respond in German. If in English, respond in English.
PROMPT;

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                null,
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.2,
                ])
            );

            return trim((string) ($response['content'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->warning('False-positive summarization failed, using fallback', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function suggestCorrection(User $user, string $text, ?string $userMessage = null): string
    {
        $toolsConfig = $this->modelConfigService->getToolsModelConfig();
        $provider = $toolsConfig['provider'];
        $modelName = $toolsConfig['model'];

        $systemPrompt = $this->getFalsePositiveCorrectionPrompt();

        // Build context with user question if available
        $contextPart = '';
        if ($userMessage) {
            $contextPart = <<<CONTEXT
User question:
{$userMessage}

CONTEXT;
        }

        $userPrompt = <<<PROMPT
{$contextPart}AI response (marked as incorrect by the user):
{$text}

Provide the corrected statement in one sentence. If the AI response was vague, provide a proper answer to the user's question.
IMPORTANT: Respond in the SAME LANGUAGE as the text above. If the text is in German, respond in German. If in English, respond in English.
PROMPT;

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                null,
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.2,
                ])
            );

            return trim((string) ($response['content'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->warning('False-positive correction failed, using fallback', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Fetch user memories related to the given text via vector search.
     * Returns both formatted text (for AI prompt) and memory IDs (for frontend targeting).
     *
     * @return array{text: string, ids: int[]}
     */
    private function fetchRelatedMemories(User $user, string $queryText): array
    {
        if (!$this->memoryService->isAvailable()) {
            return ['text' => '', 'ids' => []];
        }

        try {
            $memories = $this->memoryService->searchRelevantMemories(
                $user->getId(),
                $queryText,
                null,
                5,
                0.4,
                null,
                false
            );

            if (empty($memories)) {
                return ['text' => '', 'ids' => []];
            }

            $lines = [];
            $ids = [];
            foreach ($memories as $m) {
                if (($m['score'] ?? 0) < 0.4) {
                    continue;
                }
                $id = (int) ($m['id'] ?? 0);
                $key = (string) ($m['key'] ?? '');
                $val = (string) ($m['value'] ?? '');
                $entry = ('' !== $key && '' !== $val) ? "{$key}: {$val}" : ($val ?: $key);
                if ('' !== $entry) {
                    $lines[] = "- {$entry}";
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }

            return [
                'text' => [] === $lines ? '' : implode("\n", $lines),
                'ids' => $ids,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('Could not fetch related memories for preview context', [
                'error' => $e->getMessage(),
            ]);

            return ['text' => '', 'ids' => []];
        }
    }

    private function normalizeSummary(string $summary, string $fallback): string
    {
        $summary = trim($summary);
        if ('' === $summary) {
            $summary = trim($fallback);
        }

        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;

        if (mb_strlen($summary) < 5) {
            $summary = trim($fallback);
        }

        if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            $summary = mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH);
        }

        return $summary;
    }

    private function getFalsePositivePrompt(): string
    {
        $prompt = $this->promptRepository->findOneBy([
            'topic' => 'tools:feedback_false_positive_summary',
            'language' => 'en',
            'ownerId' => 0,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        $this->logger->warning('False-positive prompt not found, using fallback');

        return 'Summarize the false or undesirable claim in one concise sentence. Output only the sentence. ALWAYS respond in the same language as the input text.';
    }

    private function getFalsePositiveCorrectionPrompt(): string
    {
        $prompt = $this->promptRepository->findOneBy([
            'topic' => 'tools:feedback_false_positive_correction',
            'language' => 'en',
            'ownerId' => 0,
        ]);

        if ($prompt) {
            return $prompt->getPrompt();
        }

        $this->logger->warning('False-positive correction prompt not found, using fallback');

        return 'Correct the false statement in one concise sentence. Output only the corrected sentence. ALWAYS respond in the same language as the input text.';
    }

    /**
     * Research sources from the user's knowledge base (RAG documents) for a given claim.
     *
     * Searches the user's uploaded documents for relevant content, then uses AI
     * to summarize what each source says in relation to the claim.
     *
     * @return array{sources: array<array{id: int, fileName: string, excerpt: string, summary: string, score: float}>}
     */
    public function researchSources(User $user, string $claimText): array
    {
        $userId = (int) $user->getId();

        $this->logger->info('FeedbackExampleService: Researching KB sources', [
            'user_id' => $userId,
            'claim_length' => mb_strlen($claimText),
        ]);

        $rawSources = [];
        $idCounter = 0;

        // 1. Search user's uploaded documents (RAG vector search)
        try {
            $ragResults = $this->vectorSearchService->semanticSearch(
                $claimText,
                $userId,
                null,
                self::MAX_RESEARCH_SOURCES,
                self::MIN_RESEARCH_SCORE,
            );

            foreach ($ragResults as $result) {
                $chunkText = trim((string) ($result['chunk_text'] ?? ''));
                if ('' === $chunkText) {
                    continue;
                }

                $rawSources[] = [
                    'id' => $idCounter++,
                    'sourceType' => 'file',
                    'fileName' => (string) ($result['file_name'] ?? 'Unknown'),
                    'excerpt' => mb_strlen($chunkText) > 300 ? mb_substr($chunkText, 0, 300).'…' : $chunkText,
                    'fullText' => $chunkText,
                    'score' => round((float) ($result['score'] ?? 0.0), 3),
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->debug('FeedbackExampleService: RAG search failed (continuing)', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Search existing feedbacks (false_positive + positive namespaces)
        if ($this->memoryService->isAvailable()) {
            $namespaces = [
                ['category' => 'feedback_negative', 'namespace' => self::NAMESPACE_FALSE_POSITIVE, 'sourceType' => 'feedback_false'],
                ['category' => 'feedback_positive', 'namespace' => self::NAMESPACE_POSITIVE, 'sourceType' => 'feedback_correct'],
            ];

            foreach ($namespaces as $ns) {
                try {
                    $feedbacks = $this->memoryService->searchRelevantMemories(
                        $userId,
                        $claimText,
                        $ns['category'],
                        3,
                        self::MIN_RESEARCH_SCORE,
                        $ns['namespace'],
                        true
                    );

                    foreach ($feedbacks as $fb) {
                        $value = trim((string) ($fb['value'] ?? ''));
                        if ('' === $value) {
                            continue;
                        }

                        $rawSources[] = [
                            'id' => $idCounter++,
                            'sourceType' => $ns['sourceType'],
                            'fileName' => '',
                            'excerpt' => mb_strlen($value) > 300 ? mb_substr($value, 0, 300).'…' : $value,
                            'fullText' => $value,
                            'score' => round((float) ($fb['score'] ?? 0.0), 3),
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('FeedbackExampleService: Feedback search failed (continuing)', [
                        'namespace' => $ns['namespace'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 3. Search user memories (higher threshold — memories are personal, rarely useful as fact-check sources)
            try {
                $memories = $this->memoryService->searchRelevantMemories(
                    $userId,
                    $claimText,
                    null,
                    3,
                    self::MIN_MEMORY_RESEARCH_SCORE,
                    null,
                    false
                );

                foreach ($memories as $m) {
                    $key = (string) ($m['key'] ?? '');
                    $val = (string) ($m['value'] ?? '');
                    $value = ('' !== $key && '' !== $val) ? "{$key}: {$val}" : ($val ?: $key);
                    if ('' === $value) {
                        continue;
                    }

                    $rawSources[] = [
                        'id' => $idCounter++,
                        'sourceType' => 'memory',
                        'fileName' => '',
                        'excerpt' => $value,
                        'fullText' => $value,
                        'score' => round((float) ($m['score'] ?? 0.0), 3),
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->debug('FeedbackExampleService: Memory search failed (continuing)', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Post-filter: remove sources below minimum score (Qdrant may return low-score results)
        $rawSources = array_values(array_filter(
            $rawSources,
            fn (array $src) => $src['score'] >= self::MIN_RESEARCH_SCORE
        ));

        if ([] === $rawSources) {
            $this->logger->info('FeedbackExampleService: No relevant KB sources found');

            return ['sources' => []];
        }

        // Sort by score descending, limit to MAX_RESEARCH_SOURCES
        usort($rawSources, fn ($a, $b) => $b['score'] <=> $a['score']);
        $rawSources = array_slice($rawSources, 0, self::MAX_RESEARCH_SOURCES);

        // Reassign sequential IDs after sorting
        foreach ($rawSources as $i => &$src) {
            $src['id'] = $i;
        }
        unset($src);

        // Use AI to summarize what each source says about the claim
        $sources = $this->summarizeSourcesWithAi($claimText, $rawSources);

        return ['sources' => $sources];
    }

    /**
     * Use AI to generate concise summaries of what each source says about the claim.
     *
     * @param array<array{id: int, sourceType: string, fileName: string, excerpt: string, fullText: string, score: float}> $rawSources
     *
     * @return array<array{id: int, sourceType: string, fileName: string, excerpt: string, summary: string, score: float}>
     */
    private function summarizeSourcesWithAi(string $claimText, array $rawSources): array
    {
        $toolsConfig = $this->modelConfigService->getToolsModelConfig();
        $provider = $toolsConfig['provider'];
        $modelName = $toolsConfig['model'];

        // Build a single prompt with all sources for efficiency (one AI call)
        $sourcesBlock = '';
        foreach ($rawSources as $i => $src) {
            $idx = $i + 1;
            $typeLabel = match ($src['sourceType']) {
                'file' => "document: {$src['fileName']}",
                'feedback_false' => 'previously marked as incorrect',
                'feedback_correct' => 'previously confirmed as correct',
                'memory' => 'user memory',
                default => $src['sourceType'],
            };
            $sourcesBlock .= "--- Source {$idx} ({$typeLabel}) ---\n{$src['fullText']}\n\n";
        }

        $systemPrompt = <<<'PROMPT'
You are a research assistant that summarizes sources in relation to a specific claim.

Rules:
- For EACH source, write ONE concise sentence about what it says regarding the claim
- Be neutral and factual
- "user memory" sources are personal facts about the USER (their age, name, preferences, etc.) — NOT facts about the claim topic. Only include them if they directly relate to the claim.
- ALWAYS respond in the SAME LANGUAGE as the claim
- Output ONLY valid JSON: {"summaries":["summary for source 1","summary for source 2",...]}
- The number of summaries MUST match the number of sources
PROMPT;

        $userPrompt = <<<PROMPT
Claim being investigated:
{$claimText}

Sources from the knowledge base:
{$sourcesBlock}
Summarize what each source says about this topic.
PROMPT;

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                null,
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.2,
                ])
            );

            $content = trim((string) ($response['content'] ?? ''));
            $summaries = $this->parseSourceSummaries($content, count($rawSources));

            // Merge AI summaries with source metadata
            $result = [];
            foreach ($rawSources as $i => $src) {
                $result[] = [
                    'id' => $src['id'],
                    'sourceType' => $src['sourceType'],
                    'fileName' => $src['fileName'],
                    'excerpt' => $src['excerpt'],
                    'summary' => $summaries[$i] ?? $src['excerpt'],
                    'score' => $src['score'],
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('FeedbackExampleService: AI summarization failed, returning excerpts', [
                'error' => $e->getMessage(),
            ]);

            // Fallback: return excerpts without AI summary
            $result = [];
            foreach ($rawSources as $src) {
                $result[] = [
                    'id' => $src['id'],
                    'sourceType' => $src['sourceType'],
                    'fileName' => $src['fileName'],
                    'excerpt' => $src['excerpt'],
                    'summary' => $src['excerpt'],
                    'score' => $src['score'],
                ];
            }

            return $result;
        }
    }

    /**
     * Parse the AI response for source summaries.
     *
     * @return string[]
     */
    private function parseSourceSummaries(string $content, int $expectedCount): array
    {
        // Strip markdown code fences
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```\s*$/i', '', $content) ?? $content;
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        $summaries = $decoded['summaries'] ?? [];
        if (!is_array($summaries)) {
            return [];
        }

        return array_map('trim', array_slice($summaries, 0, $expectedCount));
    }

    /**
     * Research sources from the web using Brave Search for a given claim.
     *
     * Generates a search query, fetches web results, and uses AI to summarize
     * what each source says in relation to the claim.
     *
     * @return array{sources: array<array{id: int, title: string, url: string, summary: string, snippet: string}>}
     */
    public function webResearchSources(string $claimText): array
    {
        if (!$this->braveSearchService->isEnabled()) {
            $this->logger->info('FeedbackExampleService: Brave Search not enabled');

            return ['sources' => []];
        }

        $this->logger->info('FeedbackExampleService: Web research started', [
            'claim_length' => mb_strlen($claimText),
        ]);

        try {
            $searchResults = $this->braveSearchService->search($claimText, [
                'count' => self::MAX_WEB_RESULTS,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('FeedbackExampleService: Brave Search failed', [
                'error' => $e->getMessage(),
            ]);

            return ['sources' => []];
        }

        $webResults = $searchResults['results'] ?? [];
        if ([] === $webResults) {
            return ['sources' => []];
        }

        // Build raw sources from web results
        $rawSources = [];
        foreach ($webResults as $i => $result) {
            $title = trim((string) ($result['title'] ?? ''));
            $url = trim((string) ($result['url'] ?? ''));
            $description = trim((string) ($result['description'] ?? ''));

            if ('' === $title || '' === $url) {
                continue;
            }

            $rawSources[] = [
                'id' => $i,
                'title' => $title,
                'url' => $url,
                'snippet' => mb_strlen($description) > 300 ? mb_substr($description, 0, 300).'…' : $description,
                'fullText' => $description,
                'sourceName' => (string) ($result['profile']['name'] ?? parse_url($url, PHP_URL_HOST) ?? ''),
            ];
        }

        if ([] === $rawSources) {
            return ['sources' => []];
        }

        // Use AI to summarize what each web source says about the claim
        return ['sources' => $this->summarizeWebSourcesWithAi($claimText, $rawSources)];
    }

    /**
     * Use AI to generate concise summaries of what each web source says about the claim.
     *
     * @param array<array{id: int, title: string, url: string, snippet: string, fullText: string, sourceName: string}> $rawSources
     *
     * @return array<array{id: int, title: string, url: string, summary: string, snippet: string}>
     */
    private function summarizeWebSourcesWithAi(string $claimText, array $rawSources): array
    {
        $toolsConfig = $this->modelConfigService->getToolsModelConfig();
        $provider = $toolsConfig['provider'];
        $modelName = $toolsConfig['model'];

        $sourcesBlock = '';
        foreach ($rawSources as $i => $src) {
            $idx = $i + 1;
            $sourcesBlock .= "--- Source {$idx}: {$src['title']} ({$src['sourceName']}) ---\n{$src['fullText']}\n\n";
        }

        $systemPrompt = <<<'PROMPT'
You are a fact-checking research assistant that summarizes web sources in relation to a specific claim.

Rules:
- For EACH source, write ONE concise sentence explaining what this source says about the topic
- Focus on facts and whether the source supports, contradicts, or is neutral regarding the claim
- Be neutral and factual
- ALWAYS respond in the SAME LANGUAGE as the claim
- Output ONLY valid JSON: {"summaries":["summary for source 1","summary for source 2",...]}
- The number of summaries MUST match the number of sources
- No markdown, no explanation, no other text
PROMPT;

        $userPrompt = <<<PROMPT
Claim being fact-checked:
{$claimText}

Web search results:
{$sourcesBlock}
Summarize what each source says about this claim.
PROMPT;

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                null,
                array_filter([
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.2,
                ])
            );

            $content = trim((string) ($response['content'] ?? ''));
            $summaries = $this->parseSourceSummaries($content, count($rawSources));

            $result = [];
            foreach ($rawSources as $i => $src) {
                $result[] = [
                    'id' => $src['id'],
                    'title' => $src['title'],
                    'url' => $src['url'],
                    'summary' => $summaries[$i] ?? $src['snippet'],
                    'snippet' => $src['snippet'],
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('FeedbackExampleService: Web AI summarization failed, returning snippets', [
                'error' => $e->getMessage(),
            ]);

            $result = [];
            foreach ($rawSources as $src) {
                $result[] = [
                    'id' => $src['id'],
                    'title' => $src['title'],
                    'url' => $src['url'],
                    'summary' => $src['snippet'],
                    'snippet' => $src['snippet'],
                ];
            }

            return $result;
        }
    }
}
