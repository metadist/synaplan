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
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function start(User $user, array $body, bool $session, ?string $idempotencyKey): ResearchRunState
    {
        $request = ResearchRequest::fromBody($body, $session, $user, $this->chats);

        return $this->open($user, $request, $this->idempotencyKey($idempotencyKey), null);
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

        return $stopped;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function followUp(User $user, string $runId, array $body): ResearchRunState
    {
        $state = $this->owned($user, $runId);
        $type = is_string($body['type'] ?? null) ? $body['type'] : '';
        if (!in_array($type, ['question', 'revise', 'deeper', 'refresh'], true)) {
            throw new ResearchRejected(400, 'invalid', 'type', 'Choose a follow-up: a question, a revision, a deeper look, or a refresh.');
        }
        $text = trim(is_string($body['text'] ?? null) ? $body['text'] : '');
        if ('refresh' !== $type && '' === $text) {
            throw new ResearchRejected(400, 'invalid', 'text', 'Write what should change.');
        }
        $question = 'refresh' === $type ? $state->ref->question : $text;
        if (mb_strlen($question) > ResearchRequest::MAX_QUESTION) {
            throw new ResearchRejected(400, 'invalid', 'text', 'That follow-up needs 4000 characters or fewer.');
        }

        return $this->open(
            $user,
            new ResearchRequest($question, $state->ref->depth, $state->ref->chatId),
            null,
            $state->ref->id,
        );
    }

    public function delete(User $user, string $runId): void
    {
        $state = $this->owned($user, $runId);
        $this->store->delete($state->ref->id);
    }

    private function writeNow(ResearchRunState $state): ResearchRunState
    {
        $this->store->requestWriteNow($state->ref->id);
        if (ResearchStatus::WaitingAnswer !== $state->status && ResearchStatus::WaitingPlan !== $state->status) {
            return $this->store->load($state->ref->id) ?? $state;
        }
        $saved = $this->store->underClaim($state, ResearchUnitWorker::LEASE_MS, static function (ResearchRunState $fresh): bool {
            if (ResearchStatus::WaitingAnswer !== $fresh->status && ResearchStatus::WaitingPlan !== $fresh->status) {
                return false;
            }
            $fresh->questions = null;
            $fresh->advance(ResearchStatus::Writing);

            return true;
        });
        if (null === $saved) {
            throw new ResearchRejected(409, 'not_waiting', null, 'This research already moved on. Reload it to see where it is.');
        }
        $this->bus->dispatch($saved->ref->id, ResearchUnitPlan::WRITE, $saved->expectedSeq, 0);

        return $saved;
    }

    private function open(User $user, ResearchRequest $request, ?string $idempotencyKey, ?string $parent): ResearchRunState
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
        $budget = ResearchBudget::for($request->depth, ResearchBudgetConfig::defaults($request->depth), $now);
        $budget->at($now);
        $state = ResearchRunState::open(
            ResearchRunRef::start($userId, $request->depth, $request->question, $request->chatId, $now),
            $budget,
        );
        $state->parent = $parent;
        $state->idempotencyKey = $idempotencyKey;
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
        $this->bus->dispatch($state->ref->id, ResearchUnitPlan::BRIEF_PLAN, $state->expectedSeq, 0);

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
