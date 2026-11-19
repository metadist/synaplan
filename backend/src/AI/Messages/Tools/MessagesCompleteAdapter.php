<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\MessagesUsage;
use App\Entity\User;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Tool\Loop\ToolLoopAdapter;
use App\Service\Tool\Loop\ToolLoopStop;
use App\Service\Tool\Loop\ToolLoopTurn;

/**
 * Wire side of one non-streaming Messages gateway run. One instance per run.
 *
 * @phpstan-import-type LoopResult from GatewayToolLoop
 * @phpstan-import-type DispatchEntry from GatewayToolLoop
 *
 * @phpstan-type CompleteCall \Closure(array<string, mixed>): array{status: int, headers: array<string, list<string>>, body: array<string, mixed>|string, usage: MessagesUsage}
 */
final class MessagesCompleteAdapter implements ToolLoopAdapter
{
    private MessagesUsage $totalUsage;
    private int $iterations = 0;
    /** @var array<string, list<string>> */
    private array $lastHeaders = [];
    private int $lastStatus = 200;
    /** @var array<string, mixed>|string|null */
    private array|string|null $lastBody = null;
    /** @var array<string, mixed>|null */
    private ?array $lastOkBody = null;
    private int $webSearchRounds = 0;
    /** @var list<array<string, mixed>> */
    private array $content = [];
    /** @var list<array<string, mixed>> */
    private array $client = [];
    /** @var LoopResult|null */
    private ?array $result = null;

    /**
     * @param array<string, mixed>         $body
     * @param CompleteCall                 $complete one upstream model call for a request body
     * @param array<string, DispatchEntry> $dispatch
     */
    public function __construct(
        private array $body,
        private readonly \Closure $complete,
        private readonly User $user,
        private readonly array $dispatch,
        private readonly GatewayOwnedTools $owned,
        private readonly MessagesGatewayConfig $config,
        private readonly WebFetchBudget $pages,
        private readonly ?RuntimeProfile $assistant,
    ) {
        $this->totalUsage = new MessagesUsage();
    }

    public function turn(int $iteration): ToolLoopTurn
    {
        ++$this->iterations;
        $this->body = MessagesToolTurn::sanitizeRequestMessages($this->body);
        $response = ($this->complete)($this->body);
        $this->lastStatus = $response['status'];
        $this->lastHeaders = $response['headers'];
        $this->lastBody = $response['body'];
        $this->totalUsage = MessagesToolTurn::sumUsage($this->totalUsage, $response['usage']);
        if ($this->lastStatus < 400 && \is_array($this->lastBody) && 'message' === ($this->lastBody['type'] ?? 'message')) {
            $this->lastOkBody = $this->lastBody;
        }

        $wrapped = WebSearchWrapUp::alreadyWrapped($this->body) && $this->webSearchRounds > 0;
        if ($this->lastStatus >= 400 || !\is_array($this->lastBody)) {
            if ($wrapped && WebSearchWrapUp::isToolChoiceConflict($this->lastBody)) {
                return $this->recovered();
            }
            $this->result = $this->loopResult($this->lastStatus, $this->lastBody, $this->totalUsage);

            return ToolLoopTurn::stop(ToolLoopStop::Failed);
        }

        $stopReason = \is_string($this->lastBody['stop_reason'] ?? null) ? $this->lastBody['stop_reason'] : null;
        if ($wrapped && 'tool_use' === $stopReason) {
            return $this->recovered();
        }
        if ('tool_use' !== $stopReason) {
            return $this->answered($this->lastBody, $stopReason, $wrapped);
        }

        return $this->toolTurn($this->lastBody);
    }

    public function execute(array $ours): ?ToolLoopStop
    {
        $toolResults = $this->owned->execute($ours, $this->dispatch, $this->user, $this->pages, null, $this->assistant);
        $this->body = MessagesToolTurn::appendToolTurn($this->body, ServerToolReplay::withoutClientUses($this->content, $this->client), $toolResults);
        if ($this->webSearchRounds >= $this->config->webSearchMaxRounds($this->user->getId())) {
            $this->body = WebSearchWrapUp::forceWrapUp($this->body);
        }

        return null;
    }

    /**
     * The result of the run: the adapter's own end, or the last turn once a
     * limit or a turn without our tools ended the loop.
     *
     * @return LoopResult
     */
    public function result(): array
    {
        if (null !== $this->result) {
            return $this->result;
        }

        $lastBody = $this->lastBody;
        $lastStatus = $this->lastStatus;
        if (!\is_array($lastBody)) {
            $lastBody = [
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Server-side tool loop exceeded iteration or wall-clock limits.',
                ],
            ];
            $lastStatus = 502;
        }
        if ($lastStatus < 400) {
            $lastBody = WebSearchWrapUp::withEmptySearchRecovery($lastBody, $this->webSearchRounds);
        }

        return $this->loopResult($lastStatus, MessagesToolTurn::withUsage($lastBody, $this->totalUsage), $this->totalUsage);
    }

    /**
     * @param array<string, mixed> $lastBody
     */
    private function answered(array $lastBody, ?string $stopReason, bool $wrapped): ToolLoopTurn
    {
        if (WebSearchWrapUp::shouldWrapForMissingUrl($lastBody, $this->webSearchRounds, $this->body)) {
            $this->body = WebSearchWrapUp::forceWrapUp($this->body);

            return ToolLoopTurn::again();
        }
        $content = \is_array($lastBody['content'] ?? null) ? $lastBody['content'] : [];
        if ($wrapped && !WebSearchWrapUp::textHasHttpUrl(WebSearchWrapUp::assistantText($content))) {
            return $this->recovered();
        }

        $this->result = $this->loopResult(
            $this->lastStatus,
            MessagesToolTurn::withUsage(WebSearchWrapUp::withEmptySearchRecovery($lastBody, $this->webSearchRounds), $this->totalUsage),
            $this->totalUsage->withStopReason($stopReason),
        );

        return ToolLoopTurn::stop(ToolLoopStop::Answered);
    }

    /**
     * @param array<string, mixed> $lastBody
     */
    private function toolTurn(array $lastBody): ToolLoopTurn
    {
        $content = $lastBody['content'] ?? [];
        if (!\is_array($content)) {
            return ToolLoopTurn::stop(ToolLoopStop::Halted);
        }

        $partition = $this->owned->partition($content, $this->dispatch);
        if ([] !== $partition->client && [] === $partition->ours) {
            $this->result = $this->loopResult(
                $this->lastStatus,
                MessagesToolTurn::withUsage(ServerToolReplay::dropUnpairedServerUses($lastBody), $this->totalUsage),
                $this->totalUsage->withStopReason('tool_use'),
            );

            return ToolLoopTurn::stop(ToolLoopStop::ClientTools);
        }
        if ([] === $partition->ours) {
            return ToolLoopTurn::stop(ToolLoopStop::Halted);
        }

        $this->webSearchRounds += WebSearchWrapUp::countWebSearches($partition->ours);
        $this->content = $content;
        $this->client = $partition->client;

        return ToolLoopTurn::run($partition->ours);
    }

    private function recovered(): ToolLoopTurn
    {
        $this->result = [
            'status' => 200,
            'headers' => $this->lastHeaders,
            'body' => MessagesToolTurn::withUsage(WebSearchWrapUp::recoverWrapUpBody($this->body, $this->webSearchRounds, $this->lastOkBody), $this->totalUsage),
            'usage' => $this->totalUsage->withStopReason('end_turn'),
            'iterations' => $this->iterations,
        ];

        return ToolLoopTurn::stop(ToolLoopStop::Answered);
    }

    /**
     * @param array<string, mixed>|string|null $body
     *
     * @return LoopResult
     */
    private function loopResult(int $status, array|string|null $body, MessagesUsage $usage): array
    {
        return [
            'status' => $status,
            'headers' => $this->lastHeaders,
            'body' => $body,
            'usage' => $usage,
            'iterations' => $this->iterations,
        ];
    }
}
