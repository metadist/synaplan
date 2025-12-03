<?php

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Search Query Generator
 * 
 * Uses AI to generate optimized search queries from user questions.
 * Similar to MessageSorter, but focused on web search optimization.
 * 
 * Workflow:
 * 1. Load search query prompt from BPROMPTS (tools:search)
 * 2. Call AI with user question
 * 3. Parse AI response (optimized search query)
 */
class SearchQueryGenerator
{
    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $promptRepository,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger
    ) {}

    /**
     * Generate optimized search query from user question
     * 
     * @param string $userQuestion The original user question
     * @param int|null $userId User ID for model config
     * @return string Optimized search query (or original if generation fails)
     */
    public function generate(string $userQuestion, ?int $userId = null): string
    {
        $this->logger->info('SearchQueryGenerator: Starting query generation', [
            'user_id' => $userId,
            'question_length' => strlen($userQuestion)
        ]);

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
            ['role' => 'user', 'content' => $userQuestion]
        ];
        
        try {
            // Call AI for query generation
            $response = $this->aiFacade->chat($messages, $userId, [
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0.3, // Low temperature for consistent results
                'max_tokens' => 100 // Short response expected
            ]);

            $searchQuery = trim($response['content']);

            $this->logger->info('SearchQueryGenerator: Query generated', [
                'provider' => $response['provider'],
                'original' => $userQuestion,
                'generated' => $searchQuery
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
                'provider' => $e->getProviderName()
            ]);
            return $this->fallbackExtraction($userQuestion);
        } catch (\Throwable $e) {
            $this->logger->error('SearchQueryGenerator: Query generation failed', [
                'error' => $e->getMessage()
            ]);
            return $this->fallbackExtraction($userQuestion);
        }
    }

    /**
     * Fallback extraction: simple keyword extraction from question
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

