<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\ModelConfigService;
use App\Service\Usage\CostCalculationService;

/**
 * Asks the model selected for a research role. A transport failure is a failed reply, not a stack trace.
 * The cost is priced from the reported usage, so the run's cost cap sees every call.
 * A plain call with a text listener streams, so the person reads the words as they come.
 */
final readonly class AiFacadeResearchModelCaller implements ResearchModelCaller
{
    public function __construct(
        private AiFacade $ai,
        private ModelConfigService $models,
        private CostCalculationService $costs,
    ) {
    }

    public function answer(
        int $userId,
        int $modelId,
        string $system,
        string $user,
        ?StructuredOutputSchema $schema,
        ?\Closure $onText = null,
    ): ResearchModelReply {
        $provider = $this->models->getProviderForModel($modelId);
        $name = $this->models->getModelName($modelId);
        if (null === $provider || '' === $provider || null === $name || '' === $name) {
            return ResearchModelReply::failed();
        }

        $options = [
            'provider' => $provider,
            'model' => $name,
            'temperature' => 0.1,
        ];
        if ($schema instanceof StructuredOutputSchema) {
            $options['structured_output'] = $schema;
        }
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        try {
            $response = $onText instanceof \Closure && !$schema instanceof StructuredOutputSchema
                ? $this->ai->chatStream($messages, static function (mixed $chunk) use ($onText): void {
                    $text = is_string($chunk) || is_array($chunk) ? StreamChunk::visibleText($chunk) : '';
                    if ('' !== $text) {
                        $onText($text);
                    }
                }, $userId, $options)
                : $this->ai->chat($messages, $userId, $options);
        } catch (\Throwable) {
            return ResearchModelReply::failed(true);
        }

        $text = is_string($response['content'] ?? null) ? $response['content'] : '';
        $data = self::objectFrom($text);
        $ok = null === $schema ? '' !== trim($text) : is_array($data);
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $cost = $this->cost($usage, $modelId);

        return new ResearchModelReply($ok, true, $data, $text, $provider.':'.$name, $cost, ResearchCallUsage::fromResponse($response, $cost));
    }

    /**
     * @param array<mixed> $usage
     */
    private function cost(array $usage, int $modelId): float
    {
        $result = $this->costs->calculateCost(
            self::tokens($usage, 'prompt_tokens'),
            self::tokens($usage, 'completion_tokens'),
            self::tokens($usage, 'cached_tokens'),
            self::tokens($usage, 'cache_creation_tokens'),
            $modelId,
            null,
            self::tokens($usage, 'cache_creation_1h_tokens'),
        );

        return max(0.0, (float) $result->totalCost);
    }

    /**
     * @param array<mixed> $usage
     */
    private static function tokens(array $usage, string $key): int
    {
        return is_numeric($usage[$key] ?? null) ? max(0, (int) $usage[$key]) : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function objectFrom(string $text): ?array
    {
        $json = trim($text);
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $json, $fenced)) {
            $json = $fenced[1];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}
