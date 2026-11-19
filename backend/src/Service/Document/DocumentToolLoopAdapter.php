<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\AI\Service\AiFacade;
use App\Service\Chat\ProviderTranscriptCapture;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolResult;
use App\Service\Tool\Loop\ToolLoopAdapter;
use App\Service\Tool\Loop\ToolLoopStop;
use App\Service\Tool\Loop\ToolLoopTurn;

/**
 * Wire side of one document chat run on {@see AiFacade}: OpenAI-shaped
 * messages, every tool call is Synaplan's, and a per-turn operation cap ends
 * the run. One instance per run.
 */
final class DocumentToolLoopAdapter implements ToolLoopAdapter
{
    private string $content = '';
    private int $ops = 0;
    /** @var array<string, mixed> */
    private array $usage = [];
    private bool $facadeMetered = false;
    private float $charged = 0.0;
    private bool $priceKnown = true;

    /**
     * @param list<array<string, mixed>>                                              $messages
     * @param array<string, mixed>                                                    $options
     * @param \Closure(array<string, mixed>): array{0: DocumentToolResult, 1: string} $callTool
     * @param \Closure(DocumentToolResult, int): void|null                            $onStep
     */
    public function __construct(
        private readonly AiFacade $aiFacade,
        private readonly ?ProviderTranscriptCapture $transcripts,
        private array $messages,
        private readonly array $options,
        private readonly ?int $userId,
        private readonly int $maxOps,
        private readonly \Closure $callTool,
        private readonly ?\Closure $onStep,
    ) {
    }

    public function turn(int $iteration): ToolLoopTurn
    {
        $this->transcripts?->record($this->messages);
        $response = $this->aiFacade->chat($this->messages, $this->userId, $this->options);
        $this->foldRound($response);
        $this->content = (string) ($response['content'] ?? '');
        $calls = $response['tool_calls'] ?? [];
        if (!is_array($calls) || [] === $calls) {
            return ToolLoopTurn::stop(ToolLoopStop::Answered);
        }

        return ToolLoopTurn::run(array_values($calls));
    }

    /**
     * The assistant turn lists only the calls that run, so a skipped call
     * needs no result here.
     */
    public function execute(array $ours, array $skipped): ?ToolLoopStop
    {
        $this->messages[] = [
            'role' => 'assistant',
            'content' => $this->content,
            'tool_calls' => $ours,
        ];
        foreach ($ours as $call) {
            if ($this->ops >= $this->maxOps) {
                return ToolLoopStop::Halted;
            }
            $name = (string) ($call['function']['name'] ?? $call['name'] ?? '');
            $id = (string) ($call['id'] ?? $name);
            [$result, $payload] = ($this->callTool)($call);
            ++$this->ops;
            if (null !== $this->onStep) {
                ($this->onStep)($result, $this->ops);
            }
            $this->messages[] = [
                'role' => 'tool',
                'tool_call_id' => $id,
                'name' => $name,
                'content' => $payload,
            ];
        }

        return null;
    }

    public function result(DocumentSession $session): ChatToolLoopResult
    {
        return new ChatToolLoopResult(
            $this->content,
            $session,
            $this->usage,
            $this->facadeMetered,
            $this->facadeMetered ? number_format($this->charged, 6, '.', '') : null,
            $this->priceKnown,
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function foldRound(array $response): void
    {
        $roundUsage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        foreach ($roundUsage as $key => $value) {
            if (is_numeric($value)) {
                $this->usage[$key] = (int) ($this->usage[$key] ?? 0) + (int) $value;
            } else {
                $this->usage[$key] = $value;
            }
        }
        if (true !== ($response['facade_metered'] ?? false)) {
            return;
        }
        $this->facadeMetered = true;
        $roundCost = $response['facade_charged_cost'] ?? null;
        if (is_string($roundCost) && is_numeric($roundCost)) {
            $this->charged += (float) $roundCost;
        }
        if (false === ($response['facade_price_known'] ?? true)) {
            $this->priceKnown = false;
        }
    }
}
