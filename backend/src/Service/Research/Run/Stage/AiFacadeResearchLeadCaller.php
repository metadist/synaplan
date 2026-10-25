<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\Service\AiFacade;
use App\AI\ToolCalling\ToolCall;
use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolCallParser;
use App\Service\ModelConfigService;
use App\Service\Usage\CostCalculationService;

/**
 * Asks the lead with the five research tools. A model that cannot call tools
 * gets the same messages without them, so the run falls through to a reminder
 * instead of failing closed.
 */
final readonly class AiFacadeResearchLeadCaller implements ResearchLeadCaller
{
    public function __construct(
        private AiFacade $ai,
        private ModelConfigService $models,
        private CostCalculationService $costs,
        private ToolCallingTranslator $tools,
        private ToolCallParser $parser,
    ) {
    }

    public function turn(int $userId, int $modelId, array $messages, array $tools): ResearchLeadTurn
    {
        $provider = $this->models->getProviderForModel($modelId);
        $name = $this->models->getModelName($modelId);
        if (null === $provider || '' === $provider || null === $name || '' === $name) {
            return ResearchLeadTurn::failed();
        }

        $options = [
            'provider' => $provider,
            'model' => $name,
            'temperature' => 0.1,
        ];
        $declared = $this->tools->translate($provider, $name, false, $tools);
        if ([] !== $declared) {
            $options = array_merge($options, $declared);
        }

        try {
            $response = $this->ai->chat($messages, $userId, $options);
        } catch (\Throwable) {
            return ResearchLeadTurn::failed(true);
        }

        $text = is_string($response['content'] ?? null) ? $response['content'] : '';
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new ResearchLeadTurn(true, true, $text, $this->calls($response['tool_calls'] ?? null), $provider.':'.$name, $this->cost($usage, $modelId));
    }

    /**
     * @return list<ToolCall>
     */
    private function calls(mixed $raw): array
    {
        $parsed = [];
        foreach ($this->parser->fromWireToolCalls($raw) as $call) {
            $parsed[] = $call;
        }

        return $parsed;
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
}
