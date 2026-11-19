<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\MessagesEventEmitter;
use App\AI\Messages\MessagesUsage;
use App\Entity\User;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Tool\Loop\ToolLoopAdapter;
use App\Service\Tool\Loop\ToolLoopStop;
use App\Service\Tool\Loop\ToolLoopTurn;

/**
 * Wire side of one streaming Messages gateway run: one client-visible
 * message, Synaplan's tool rounds suppressed from the wire, keep-alive pings
 * while tools run. One instance per run.
 *
 * @phpstan-import-type DispatchEntry from GatewayToolLoop
 * @phpstan-import-type StreamedTurn from MessagesTurnCollector
 * @phpstan-import-type StreamCall from MessagesTurnCollector
 */
final class MessagesStreamAdapter implements ToolLoopAdapter
{
    private MessagesUsage $totalUsage;
    private ?MessagesUsage $finalUsage = null;
    private int $webSearchRounds = 0;
    /** @var list<array<string, mixed>> */
    private array $content = [];
    /** @var list<array<string, mixed>> */
    private array $client = [];

    /**
     * @param array<string, mixed>         $body
     * @param StreamCall                   $stream   one streaming upstream model call for a request body
     * @param array<string, DispatchEntry> $dispatch
     */
    public function __construct(
        private array $body,
        private readonly \Closure $stream,
        private readonly MessagesEventEmitter $emitter,
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
        $this->emitter->resetTurnMapping();
        $this->body = MessagesToolTurn::sanitizeRequestMessages($this->body);
        $canRecoverWrapUp = WebSearchWrapUp::alreadyWrapped($this->body) && $this->webSearchRounds > 0;
        $collector = new MessagesTurnCollector($this->emitter, array_keys($this->dispatch), relayErrors: !$canRecoverWrapUp);
        $turn = $collector->collect(
            $this->stream,
            $this->body,
            fn (array $content): bool => [] !== $this->owned->partition($content, $this->dispatch)->ours,
        );
        $this->totalUsage = MessagesToolTurn::sumUsage($this->totalUsage, $turn['usage']);

        if ($turn['error']) {
            if ($canRecoverWrapUp) {
                return $this->recovered(['held_stop' => null]);
            }

            return $this->finish($turn, $this->totalUsage, ToolLoopStop::Failed);
        }
        if ($canRecoverWrapUp && 'tool_use' === $turn['stop_reason']) {
            return $this->recovered(['held_stop' => null]);
        }
        if ('tool_use' !== $turn['stop_reason']) {
            return $this->answered($turn);
        }

        $partition = $this->owned->partition($turn['content'], $this->dispatch);
        if ([] === $partition->ours) {
            // Nothing of ours left to run. Close and let the client take over.
            return $this->finish($turn, $this->totalUsage->withStopReason('tool_use'), ToolLoopStop::ClientTools);
        }

        $this->webSearchRounds += WebSearchWrapUp::countWebSearches($partition->ours);
        $this->content = $turn['content'];
        $this->client = $partition->client;

        return ToolLoopTurn::run($partition->ours);
    }

    public function execute(array $ours): ?ToolLoopStop
    {
        $emitter = $this->emitter;
        $toolResults = $this->owned->execute(
            $ours,
            $this->dispatch,
            $this->user,
            $this->pages,
            ping: static function () use ($emitter): void {
                $emitter->emitPing();
            },
            assistant: $this->assistant,
        );
        $this->body = MessagesToolTurn::appendToolTurn($this->body, ServerToolReplay::withoutClientUses($this->content, $this->client), $toolResults);
        if ($this->webSearchRounds >= $this->config->webSearchMaxRounds($this->user->getId())) {
            $this->body = WebSearchWrapUp::forceWrapUp($this->body);
        }

        return null;
    }

    /**
     * The usage of the run. A run a limit ended closes the stream here.
     */
    public function usage(): MessagesUsage
    {
        if (null !== $this->finalUsage) {
            return $this->finalUsage;
        }

        if ($this->webSearchRounds > 0 && !$this->emitter->hasEmittedText()) {
            $this->emitter->emitAssistantText(WebSearchWrapUp::EMPTY_SEARCH_RECOVERY);
        }
        $this->emitter->ensureClosed();

        return $this->totalUsage;
    }

    /**
     * @param StreamedTurn $turn
     */
    private function answered(array $turn): ToolLoopTurn
    {
        if ($this->webSearchRounds > 0 && !$this->emitter->hasEmittedText()) {
            $this->emitter->emitAssistantText(WebSearchWrapUp::EMPTY_SEARCH_RECOVERY);

            return $this->finish($turn, $this->totalUsage->withStopReason($turn['stop_reason']), ToolLoopStop::Answered);
        }
        if (WebSearchWrapUp::shouldWrapForMissingUrlFromTurn($turn, $this->webSearchRounds, $this->body)) {
            $this->body = WebSearchWrapUp::forceWrapUp($this->body);

            return ToolLoopTurn::again();
        }
        if (WebSearchWrapUp::alreadyWrapped($this->body)
            && $this->webSearchRounds > 0
            && !WebSearchWrapUp::textHasHttpUrl(WebSearchWrapUp::assistantText($turn['content']))
        ) {
            return $this->recovered($turn);
        }

        return $this->finish($turn, $this->totalUsage->withStopReason($turn['stop_reason']), ToolLoopStop::Answered);
    }

    /**
     * @param array{held_stop?: array{event: string, data: array<string, mixed>}|null} $turn
     */
    private function recovered(array $turn): ToolLoopTurn
    {
        $this->emitter->emitAssistantText(WebSearchWrapUp::recoverWrapUpText($this->body, $this->webSearchRounds));

        return $this->finish($turn, $this->totalUsage->withStopReason('end_turn'), ToolLoopStop::Answered);
    }

    /**
     * @param array{held_stop?: array{event: string, data: array<string, mixed>}|null} $turn
     */
    private function finish(array $turn, MessagesUsage $usage, ToolLoopStop $stop): ToolLoopTurn
    {
        MessagesToolTurn::finishStream($this->emitter, $turn);
        $this->finalUsage = $usage;

        return ToolLoopTurn::stop($stop);
    }
}
