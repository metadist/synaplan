<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/** The run as a client reads it. The retry key stays on the server. */
final class ResearchRunView
{
    /**
     * @param list<array{seq: int, event: string, data: array<string, mixed>}> $events
     *
     * @return array{id: string, status: string, events: list<array{seq: int, event: string, data: array<string, mixed>}>, research_protocol: int}
     */
    public static function accepted(ResearchRunState $state, array $events): array
    {
        return [
            'id' => $state->ref->id,
            'status' => $state->status->value,
            'events' => $events,
            'research_protocol' => ResearchCapabilities::PROTOCOL,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(ResearchRunState $state, bool $stopRequested, bool $writeRequested, ?int $queuedSince): array
    {
        $ledger = $state->ledger->toArray();
        $budget = $state->budget->toArray();
        $report = $state->ledger->report();

        return [
            'id' => $state->ref->id,
            'research_protocol' => ResearchCapabilities::PROTOCOL,
            'status' => $state->status->value,
            'depth' => $state->ref->depth->value,
            'question' => $state->ref->question,
            'parent' => $state->parent,
            'follow_up' => $state->followUp,
            'can_add_to_report' => ResearchFollowUp::QUESTION === $state->followUp && ResearchStatus::Complete === $state->status && null !== $report,
            'stop_requested' => $stopRequested,
            'write_requested' => $writeRequested,
            'queued_since' => $queuedSince,
            'questions' => null === $state->questions ? [] : array_map(
                static fn (ResearchQuestion $item): array => $item->toArray(),
                $state->questions->items,
            ),
            'brief' => $ledger['brief'],
            'plan' => $ledger['plan'],
            'sources' => $ledger['sources'],
            'evidence' => $ledger['evidence'],
            'report' => null === $report ? null : ['markdown' => $report, 'version' => 1],
            'check' => $state->ledger->check(),
            'outcome' => $state->outcome?->toArray(),
            'models' => $ledger['models'],
            'budget' => [
                'searches' => $budget['searches'],
                'sources_read' => $budget['sources_read'],
                'rounds' => $budget['rounds'],
                'seconds' => $budget['seconds'],
            ],
            'cost' => [
                'estimate' => $budget['cost']['estimate'],
                'spent' => $budget['cost']['spent'],
                'currency' => 'USD',
            ],
            'created_at' => $state->ref->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => ($state->updatedAt ?? $state->ref->createdAt)->format(\DateTimeInterface::ATOM),
            'expires_at' => $state->expiresAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
