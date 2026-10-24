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
 */
final readonly class ResearchRunService
{
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
     * @return list<array{id: string, status: string, depth: string, question: string, created_at: string}>
     */
    public function listRecent(User $user): array
    {
        $cutoff = $this->clock->now()->modify('-48 hours');
        $rows = [];
        foreach ($this->store->runsFor($user->getId() ?? 0) as $run) {
            if ($run->ref->createdAt < $cutoff) {
                continue;
            }
            $rows[] = [
                'id' => $run->ref->id,
                'status' => $run->status->value,
                'depth' => $run->ref->depth->value,
                'question' => $run->ref->question,
                'created_at' => $run->ref->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
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
        if (ResearchStatus::WaitingAnswer === $state->status && in_array($type, ['answer', 'skip_question'], true)) {
            return $this->continueWaiting($state, ResearchStatus::Planning, ResearchUnitPlan::BRIEF_PLAN);
        }
        if (ResearchStatus::WaitingPlan === $state->status && 'approve_plan' === $type) {
            return $this->continueWaiting($state, ResearchStatus::Gathering, ResearchUnitPlan::ROUND);
        }
        if (ResearchStatus::WaitingAnswer === $state->status || ResearchStatus::WaitingPlan === $state->status) {
            throw new ResearchRejected(400, 'invalid', 'type', 'That reply is not available for this research.');
        }

        throw new ResearchRejected(409, 'not_waiting', null, 'This research is not waiting for a reply.');
    }

    public function cancel(User $user, string $runId): ResearchRunState
    {
        $state = $this->owned($user, $runId);
        $this->store->requestStop($state->ref->id);
        if ($state->status->isTerminal() || $this->store->isClaimed($state->ref->id, $state->expectedSeq)) {
            return $state;
        }
        $outcome = new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER);
        $state->finish(ResearchStatus::Stopped, $outcome);
        $this->store->save($state);
        $this->events->append($state->ref->id, 'stopped', ['outcome' => $outcome->toArray()]);
        if (null !== $state->ref->chatId) {
            $this->reports->deliver($state);
        }

        return $state;
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
        if (mb_strlen($question) > 4000) {
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

    private function continueWaiting(ResearchRunState $state, ResearchStatus $status, string $unit): ResearchRunState
    {
        $state->advance($status);
        $this->store->save($state);
        $this->bus->dispatch($state->ref->id, $unit, $state->expectedSeq, 0);

        return $state;
    }

    private function open(User $user, ResearchRequest $request, ?string $idempotencyKey, ?string $parent): ResearchRunState
    {
        $userId = $user->getId() ?? 0;
        if (null !== $idempotencyKey) {
            $existing = $this->store->findIdempotency($userId, $idempotencyKey);
            if (null !== $existing) {
                $again = $this->store->load($existing);
                if (null !== $again && $again->ref->userId === $userId) {
                    return $again;
                }
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

                return $this->owned($user, $winner);
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

    private function guardLimits(int $userId, ResearchDepth $depth): void
    {
        $active = 0;
        $thorough = 0;
        foreach ($this->store->runsFor($userId) as $run) {
            if ($run->status->isTerminal()) {
                continue;
            }
            ++$active;
            if (ResearchDepth::Thorough === $run->ref->depth) {
                ++$thorough;
            }
        }
        if ($active >= $this->config->maxActiveRuns()) {
            throw new ResearchRejected(429, 'too_many', null, 'Too many research runs are already going. Wait for one to finish, or stop one.');
        }
        if (ResearchDepth::Thorough === $depth && $thorough >= $this->config->maxActiveThorough()) {
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
