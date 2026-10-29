<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\AI\Tool\ToolCallAccumulator;
use App\AI\ToolCalling\ToolCall;
use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolCallParser;
use App\Service\ModelConfigService;
use App\Service\Usage\CostCalculationService;

/**
 * Asks the lead with the five research tools. A model that cannot call tools
 * gets the same messages without them, so the run falls through to a reminder
 * instead of failing closed. With a listener the turn is streamed, so the
 * person reads the lead's note while the model still writes it.
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

    public function turn(int $userId, int $modelId, array $messages, array $tools, ?\Closure $onText = null): ResearchLeadTurn
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
        // A provider that cannot call tools while streaming is asked without the stream, never without the tools.
        $streamed = $onText instanceof \Closure ? $this->tools->translate($provider, $name, true, $tools) : [];
        $stream = $onText instanceof \Closure && ([] === $tools || [] !== $streamed);
        $declared = $stream ? $streamed : $this->tools->translate($provider, $name, false, $tools);
        if ([] !== $declared) {
            $options = array_merge($options, $declared);
        }

        $deltas = new ToolCallAccumulator();
        $listener = $stream ? $onText : null;
        try {
            $response = $listener instanceof \Closure
                ? $this->ai->chatStream($messages, self::forward($listener, $deltas), $userId, $options)
                : $this->ai->chat($messages, $userId, $options);
        } catch (\Throwable) {
            return ResearchLeadTurn::failed(true);
        }

        $text = is_string($response['content'] ?? null) ? $response['content'] : '';
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $cost = $this->cost($usage, $modelId);
        $wire = $response['tool_calls'] ?? null;
        if ((!is_array($wire) || [] === $wire) && !$deltas->isEmpty()) {
            $wire = $deltas->complete();
        }

        return new ResearchLeadTurn(true, true, $text, $this->calls($wire), $provider.':'.$name, $cost, ResearchCallUsage::fromResponse($response, $cost));
    }

    /**
     * The stream listener: prose goes to the person, tool-call pieces are
     * kept in case the provider's result does not carry the assembled calls.
     *
     * @param \Closure(string): void $onText
     */
    private static function forward(\Closure $onText, ToolCallAccumulator $deltas): \Closure
    {
        return static function (mixed $chunk) use ($onText, $deltas): void {
            if (is_array($chunk)) {
                $deltas->addDelta($chunk);
            }
            $text = is_string($chunk) || is_array($chunk) ? StreamChunk::visibleText($chunk) : '';
            if ('' !== $text) {
                $onText($text);
            }
        };
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
