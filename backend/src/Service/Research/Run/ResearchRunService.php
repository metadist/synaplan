<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Repository\ChatRepository;
use Psr\Clock\ClockInterface;

/**
 * Opens, lists, stops and deletes a research run.
 *
 * Model hints, a world and starting pages are accepted on the request and
 * not applied yet: the run uses the installation's models. Reading whole
 * documents stays off.
 *
 * Every change to a run that already exists goes through the unit's claim,
 * so a worker or the reaper that acts at the same moment is never overwritten.
 */
final readonly class ResearchRunService
{
    private const LIST_HOURS = 48;

    public function __construct(
        private ResearchStateStore $store,
        private ResearchUnitBus $bus,
        private ResearchEventLog $events,
        private ResearchConfig $config,
        private ChatRepository $chats,
        private ClockInterface $clock,
        private ResearchReportPoster $reports,
        private ?ResearchSavedTaskSignal $savedTasks = null,
        private ?ResearchBudgetStore $budgets = null,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function start(User $user, array $body, bool $session, ?string $idempotencyKey): ResearchRunState
    {
        $request = ResearchRequest::fromBody($body, $session, $user, $this->chats);

        return $this->open($user, $request, $this->idempotencyKey($idempotencyKey), null, null);
    }

    /**
     * A saved-task step. Quick or standard only, no questions, and the same
     * step always returns the run it already started.
     */
    public function startForSavedTask(
        User $user,
        string $question,
        string $depth,
        int $chatId,
        int $taskRunId,
        string $nodeId,
    ): ResearchRunState {
        if (!$this->config->isEnabled()) {
            throw new ResearchRejected(404, 'feature_not_configured', null, 'Research is turned off, so this step did not start.');
        }
        if (!in_array($depth, [ResearchDepth::Quick->value, ResearchDepth::Standard->value], true)) {
            throw new ResearchRejected(400, 'invalid', 'depth', 'A saved task can only run a quick or a standard research.');
        }
        if ($taskRunId < 1 || '' === $nodeId) {
            throw new ResearchRejected(400, 'invalid', null, 'This step runs inside a saved task. Research did not start.');
        }
        $request = ResearchRequest::fromBody([
            'question' => $question,
            'depth' => $depth,
            'chat_id' => $chatId,
        ], true, $user, $this->chats);
        // A slash never passes idempotencyKey(), so a client key cannot claim this run.
        $key = 'saved-task/'.$taskRunId.'/'.substr(hash('sha256', $nodeId), 0, 16);

        return $this->open($user, $request, $key, null, null, new ResearchTaskLink($taskRunId, $nodeId));
    }

    public function state(User $user, string $runId): ResearchRunState
    {
        return $this->owned($user, $runId);
    }

    /**
     * The caller's runs of the last 48 hours, newest first.
     *
     * @return list<array{id: string, status: string, depth: string, question: string, created_at: string}>
     */
    public function listRecent(User $user, bool $activeOnly = false): array
    {
        $cutoff = $this->clock->now()->modify('-'.self::LIST_HOURS.' hours');
        $runs = array_filter(
            $this->store->runsFor($user->getId() ?? 0),
            static fn (ResearchRunState $run): bool => $run->ref->createdAt >= $cutoff
                && (!$activeOnly || !$run->status->isTerminal()),
        );
        usort($runs, static fn (ResearchRunState $a, ResearchRunState $b): int => $b->ref->createdAt <=> $a->ref->createdAt);

        return array_map(static fn (ResearchRunState $run): array => [
            'id' => $run->ref->id,
            'status' => $run->status->value,
            'depth' => $run->ref->depth->value,
            'question' => $run->ref->question,
            'created_at' => $run->ref->createdAt->format(\DateTimeInterface::ATOM),
        ], $runs);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function input(User $user, string $runId, array $body): ResearchRunState
    {
        $state = $this->owned($user, $runId);
        if ($state->status->isTerminal()) {
            throw new ResearchRejected(409, 'not_waiting', null, 'This research has already finished.');
        }
        $type = is_string($body['type'] ?? null) ? $body['type'] : '';
        if ('stop_gathering' === $type) {
            return $this->writeNow($state);
        }
        $unit = null;
        $saved = $this->store->underClaim($state, ResearchUnitWorker::LEASE_MS, static function (ResearchRunState $fresh) use ($body, &$unit): bool {
            $unit = ResearchReply::apply($fresh, $body);

            return true;
        });
        if (null === $saved) {
            throw new ResearchRejected(409, 'not_waiting', null, 'This research already moved on. Reload it to see where it is.');
        }
        if (is_string($unit)) {
            $this->bus->dispatch($saved->ref->id, $unit, $saved->expectedSeq, 0);
        }

        return $saved;
    }

    /**
     * Ask the run to stop. A run nobody is working on stops right away and
     * its chat hears about it; a unit that is underway sees the stop before
     * its next paid call and closes the run itself.
     */
    public function cancel(User $user, string $runId): ResearchRunState
    {
        $state = $this->owned($user, $runId);
        if ($state->status->isTerminal()) {
            return $state;
        }
        $this->store->requestStop($state->ref->id);
        $outcome = new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER);
        $stopped = $this->store->underClaim($state, ResearchUnitWorker::LEASE_MS, static function (ResearchRunState $fresh) use ($outcome): bool {
            $fresh->finish(ResearchStatus::Stopped, $outcome);

            return true;
        });
        if (null === $stopped) {
            return $this->store->load($state->ref->id) ?? $state;
        }
        $this->events->append($stopped->ref->id, 'stopped', ['outcome' => $outcome->toArray()]);
        $this->reports->deliver($stopped);
        $this->savedTasks?->after($stopped);

        return $stopped;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function followUp(User $user, string $runId, array $body, ?string $idempotencyKey = null): ResearchRunState
    {
        $state = $this->owned($user, $runId);
        $type = is_string($body['type'] ?? null) ? $body['type'] : '';
        if (!in_array($type, ResearchFollowUp::KINDS, true)) {
            throw new ResearchRejected(400, 'invalid', 'type', 'Choose a follow-up: a question, a revision, a deeper look, or a refresh.');
        }
        $text = trim(is_string($body['text'] ?? null) ? $body['text'] : '');
        if (ResearchFollowUp::QUESTION === $type && '' === $text) {
            throw new ResearchRejected(400, 'invalid', 'text', 'Write your question.');
        }
        if (ResearchFollowUp::REVISE === $type && '' === $text) {
            throw new ResearchRejected(400, 'invalid', 'text', 'Write what should change.');
        }
        if (mb_strlen($text) > ResearchRequest::MAX_QUESTION) {
            throw new ResearchRejected(400, 'invalid', 'text', 'That follow-up needs 4000 characters or fewer.');
        }
        if (!$state->status->isTerminal()) {
            throw new ResearchRejected(409, 'conflict', 'type', 'Wait until this research finishes. Then you can ask a follow-up.');
        }
        if (ResearchFollowUp::REVISE === $type && null === $state->ledger->report()) {
            throw new ResearchRejected(409, 'conflict', 'type', 'This research has no report to revise. Ask a question or look deeper instead.');
        }
        $key = $this->idempotencyKey($idempotencyKey);
        $plan = ResearchFollowUp::prepare($state, $type, $text);
        $question = ResearchFollowUp::QUESTION === $type ? $text : $state->ref->question;

        return $this->open(
            $user,
            new ResearchRequest($question, $plan->depth, $state->ref->chatId),
            $key,
            $state->ref->id,
            $plan,
        );
    }

    public function delete(User $user, string $runId): void
    {
        $state = $this->owned($user, $runId);
        $this->store->delete($state->ref->id);
        $this->savedTasks?->deleted($state);
    }

    /**
     * The flag is raised first, so a step that is underway writes next. A
     * waiting run has no step underway and goes to the report at once.
     */
    private function writeNow(ResearchRunState $state): ResearchRunState
    {
        $this->store->requestWriteNow($state->ref->id);
        if (!ResearchReply::isWaiting($state)) {
            return $this->store->load($state->ref->id) ?? $state;
        }
        $saved = $this->store->underClaim($state, ResearchUnitWorker::LEASE_MS, static fn (ResearchRunState $fresh): bool => ResearchReply::writeNow($fresh));
        if (null === $saved) {
            return $this->store->load($state->ref->id) ?? $state;
        }
        $this->bus->dispatch($saved->ref->id, ResearchUnitPlan::WRITE, $saved->expectedSeq, 0);

        return $saved;
    }

    private function open(User $user, ResearchRequest $request, ?string $idempotencyKey, ?string $parent, ?ResearchFollowUp $plan, ?ResearchTaskLink $task = null): ResearchRunState
    {
        $userId = $user->getId() ?? 0;
        if (null !== $idempotencyKey) {
            $again = $this->repeated($userId, $idempotencyKey);
            if (null !== $again) {
                return $again;
            }
        }
        $this->guardLimits($userId, $request->depth);
        $now = $this->clock->now();
        $limits = $this->budgets?->forDepth($request->depth) ?? ResearchBudgetConfig::defaults($request->depth);
        if (null !== $plan?->searchCap) {
            $limits = $limits->withSearches($plan->searchCap);
        }
        if (null !== $task) {
            $limits = $limits->withoutQuestions();
        }
        $budget = ResearchBudget::for($request->depth, $limits, $now);
        $budget->at($now);
        $state = ResearchRunState::open(
            ResearchRunRef::start($userId, $request->depth, $request->question, $request->chatId, $now),
            $budget,
            $plan?->ledger,
        );
        $state->parent = $parent;
        $state->idempotencyKey = $idempotencyKey;
        if (null !== $task) {
            $state->savedTaskRunId = $task->runId;
            $state->savedTaskNodeId = $task->nodeId;
        }
        $unit = ResearchUnitPlan::BRIEF_PLAN;
        if (null !== $plan) {
            $state->followUp = $plan->kind;
            $state->instruction = $plan->instruction;
            $state->priorReport = $plan->priorReport;
            $unit = $plan->unit;
            if (ResearchStatus::Briefing !== $plan->status) {
                $state->advance($plan->status);
            }
        }
        $this->store->save($state);
        if (null !== $idempotencyKey) {
            $winner = $this->store->claimIdempotency($userId, $idempotencyKey, $state->ref->id);
            if (null !== $winner && $winner !== $state->ref->id) {
                $this->store->delete($state->ref->id);

                return $this->repeated($userId, $idempotencyKey)
                    ?? throw new ResearchRejected(409, 'conflict', 'Idempotency-Key', 'Another start with this key is still being set up. Try again in a moment.');
            }
        }
        $this->events->append($state->ref->id, 'run', [
            'id' => $state->ref->id,
            'status' => $state->status->value,
            'depth' => $request->depth->value,
            'research_protocol' => ResearchCapabilities::PROTOCOL,
        ]);
        $this->bus->dispatch($state->ref->id, $unit, $state->expectedSeq, 0);

        return $state;
    }

    private function repeated(int $userId, string $idempotencyKey): ?ResearchRunState
    {
        $existing = $this->store->findIdempotency($userId, $idempotencyKey);
        if (null === $existing) {
            return null;
        }
        $again = $this->store->load($existing);

        return null !== $again && $again->ref->userId === $userId ? $again : null;
    }

    private function guardLimits(int $userId, ResearchDepth $depth): void
    {
        if ($this->store->activeCount($userId) >= $this->config->maxActiveRuns()) {
            throw new ResearchRejected(429, 'too_many', null, 'Too many research runs are already going. Wait for one to finish, or stop one.');
        }
        if (ResearchDepth::Thorough === $depth && $this->store->activeCount($userId, ResearchDepth::Thorough) >= $this->config->maxActiveThorough()) {
            throw new ResearchRejected(429, 'too_many', 'depth', 'A thorough research is already going. Wait for it to finish, or pick a shorter one.');
        }
    }

    private function owned(User $user, string $runId): ResearchRunState
    {
        if (!ResearchIds::isValid($runId)) {
            throw new ResearchRunNotFound($runId);
        }
        $state = $this->store->load($runId);
        if (null === $state || $state->ref->userId !== ($user->getId() ?? 0)) {
            throw new ResearchRunNotFound($runId);
        }

        return $state;
    }

    private function idempotencyKey(?string $key): ?string
    {
        if (null === $key || '' === trim($key)) {
            return null;
        }
        $key = trim($key);
        if (strlen($key) > 64 || 1 !== preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw new ResearchRejected(400, 'invalid', 'Idempotency-Key', 'The idempotency key must be 1 to 64 letters, digits, or . _ : -.');
        }

        return $key;
    }
}
