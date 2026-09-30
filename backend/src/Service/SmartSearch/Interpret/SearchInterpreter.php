<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Interpret;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\Schema\SmartSearchInterpretSchema;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\RateLimitService;
use App\Service\SmartSearch\SearchModelConfigService;
use App\Service\SmartSearch\SmartSearchConfig;
use Psr\Log\LoggerInterface;

/**
 * The AI tier of the search palette: one tools-model call that reads a
 * question plus the results the palette already found, and points at the
 * best of them. It never acts; the palette shows the pick and the user
 * decides.
 */
final readonly class SearchInterpreter
{
    public const PROMPT_TOPIC = 'tools:smart_search';

    private const MAX_TARGETS = 3;
    private const MAX_ANSWER_LENGTH = 240;
    private const TEMPERATURE = 0.1;

    public function __construct(
        private SmartSearchConfig $config,
        private SearchModelConfigService $searchModels,
        private AiFacade $aiFacade,
        private PromptRepository $prompts,
        private StructuredOutputConfig $structuredOutputConfig,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Flag on and a search AI model whose provider can answer right now.
     */
    public function isAvailable(User $user): bool
    {
        if (!$this->config->isAiEnabled($user->getId())) {
            return false;
        }

        $model = $this->searchModels->aiModel($user->getId());

        return null !== $model && $this->searchModels->availability($model)['available'];
    }

    /**
     * @param list<InterpretCandidate> $candidates
     */
    public function interpret(User $user, string $query, array $candidates, string $language): InterpretResult
    {
        $model = $this->searchModels->aiModel($user->getId());
        $options = ['temperature' => self::TEMPERATURE];
        if (null !== $model) {
            $options['provider'] = strtolower($model->getService());
            $options['model'] = $model->getProviderId() ?: $model->getName();
        }
        if ($this->structuredOutputConfig->isEnabled($user->getId())) {
            $options['structured_output'] = SmartSearchInterpretSchema::build();
        }

        $userPrompt = $this->userPrompt($query, $candidates, $language);

        try {
            $response = $this->aiFacade->chat([
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $userPrompt],
            ], $user->getId(), $options);
        } catch (\Throwable $e) {
            $this->logger->warning('Smart search interpret call failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return InterpretResult::failed();
        }

        $content = is_string($response['content'] ?? null) ? $response['content'] : '';
        $this->rateLimitService->recordUsage($user, 'SMART_SEARCH', [
            'provider' => $response['provider'] ?? 'unknown',
            'model' => $response['model'] ?? 'unknown',
            'model_id' => $model?->getId(),
            'usage' => $response['usage'] ?? [],
            'response_text' => $content,
            'input_text' => $userPrompt,
        ]);

        return self::parse($content, $candidates);
    }

    /**
     * @param list<InterpretCandidate> $candidates
     */
    public static function parse(string $raw, array $candidates): InterpretResult
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $trimmed);
        }

        try {
            $decoded = json_decode($trimmed, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return InterpretResult::failed();
        }
        if (!is_array($decoded) || !is_string($decoded['intent'] ?? null) || !in_array($decoded['intent'], InterpretResult::INTENTS, true)) {
            return InterpretResult::failed();
        }
        $intent = $decoded['intent'];

        $known = array_flip(array_map(static fn (InterpretCandidate $c): string => $c->id, $candidates));
        $targetIds = [];
        foreach (is_array($decoded['targetIds'] ?? null) ? $decoded['targetIds'] : [] as $id) {
            if (is_string($id) && isset($known[$id]) && !in_array($id, $targetIds, true)) {
                $targetIds[] = $id;
            }
        }
        $targetIds = array_slice($targetIds, 0, self::MAX_TARGETS);

        $answer = is_string($decoded['answer'] ?? null)
            ? trim((string) preg_replace('/\s+/u', ' ', $decoded['answer']))
            : '';
        $answer = '' === $answer ? null : mb_substr($answer, 0, self::MAX_ANSWER_LENGTH);

        if ([] === $targetIds && 'answer' !== $intent) {
            return new InterpretResult('no_match', null, [], $answer);
        }

        return new InterpretResult('ok', $intent, $targetIds, $answer);
    }

    private function systemPrompt(): string
    {
        $prompt = $this->prompts->findByTopic(self::PROMPT_TOPIC, 0);

        return $prompt?->getPrompt() ?? 'You are the Smart Search Interpreter. Pick up to 3 candidate ids that best answer the question, never other ids. Return only JSON {"intent","targetIds","answer"}.';
    }

    /**
     * @param list<InterpretCandidate> $candidates
     */
    private function userPrompt(string $query, array $candidates, string $language): string
    {
        $lines = array_map(static fn (InterpretCandidate $c): string => $c->toPromptLine(), $candidates);

        return "Language: {$language}\n\nQuestion:\n{$query}\n\nCandidates:\n".implode("\n", $lines);
    }
}
