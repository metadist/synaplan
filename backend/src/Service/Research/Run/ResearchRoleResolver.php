<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;

/**
 * Picks one model per research role.
 *
 * Slots fall through in the order of 02 §7.1. A model that is retired,
 * disallowed, unhealthy or outside the world is skipped. Nothing is filled
 * from a provider the world does not allow.
 */
final readonly class ResearchRoleResolver
{
    public function __construct(
        private ResearchModelLookup $models,
        private ResearchInputBudget $budgets = new ResearchInputBudget(),
    ) {
    }

    public function resolve(User $user, ResearchDepth $depth, ResearchWorld $world, ResearchModelHints $hints, ?int $leadModelId): ResearchRoles
    {
        $userId = (int) $user->getId();
        $lead = $this->lead($userId, $depth, $world, $hints, $leadModelId);
        $writer = $this->writer($userId, $world, $hints, $lead);
        $reader = $this->one($userId, $world, $hints->reader, ['READ', 'SORT', 'ANALYZE', 'CHAT'], 'reader');
        $checker = $this->checker($userId, $world, $hints, $writer);

        return new ResearchRoles(
            sorter: $this->one($userId, $world, $hints->sorter, ['SORT'], 'sorter')->id,
            reader: $reader->id,
            lead: $lead->id,
            writer: $writer->id,
            checker: $checker['choice']->id,
            checkerSameFamily: $checker['sameFamily'],
            disclosures: $this->disclosures($reader, $checker['sameFamily']),
        );
    }

    private function lead(int $userId, ResearchDepth $depth, ResearchWorld $world, ResearchModelHints $hints, ?int $leadModelId): ResearchModelChoice
    {
        if (ResearchDepth::Thorough === $depth && null !== $hints->thoroughLead) {
            $preset = $this->accept($userId, $this->models->byKey($userId, $hints->thoroughLead), $world);
            if (null !== $preset) {
                return $preset;
            }
        }

        if (null !== $hints->lead) {
            $hinted = $this->accept($userId, $this->models->byKey($userId, $hints->lead), $world);
            if (null !== $hinted) {
                return $hinted;
            }
        }

        if (null !== $leadModelId) {
            $selected = $this->accept($userId, $this->models->byId($userId, $leadModelId), $world);
            if (null !== $selected) {
                return $selected;
            }
        }

        return $this->one($userId, $world, null, ['CHAT'], 'lead');
    }

    private function writer(int $userId, ResearchWorld $world, ResearchModelHints $hints, ResearchModelChoice $lead): ResearchModelChoice
    {
        if (null !== $hints->writer) {
            $split = $this->accept($userId, $this->models->byKey($userId, $hints->writer), $world);
            if (null !== $split) {
                return $split;
            }
        }

        return $lead;
    }

    /**
     * @param list<string> $slots
     */
    private function one(int $userId, ResearchWorld $world, ?string $hint, array $slots, string $role): ResearchModelChoice
    {
        if (null !== $hint) {
            $hinted = $this->accept($userId, $this->models->byKey($userId, $hint), $world);
            if (null !== $hinted) {
                return $hinted;
            }
        }

        foreach ($slots as $slot) {
            $picked = $this->accept($userId, $this->models->slot($userId, $slot), $world);
            if (null !== $picked) {
                return $picked;
            }
        }

        throw new RoleUnavailable($role);
    }

    /**
     * @return array{choice: ResearchModelChoice, sameFamily: bool}
     */
    private function checker(int $userId, ResearchWorld $world, ResearchModelHints $hints, ResearchModelChoice $writer): array
    {
        $choice = $this->one($userId, $world, $hints->checker, ['VERIFY', 'ANALYZE', 'CHAT'], 'checker');
        if ('' === $choice->family || $choice->family !== $writer->family) {
            return ['choice' => $choice, 'sameFamily' => false];
        }

        foreach ($this->models->verifyCandidates($userId) as $candidate) {
            $other = $this->accept($userId, $candidate, $world);
            if (null !== $other && '' !== $other->family && $other->family !== $writer->family) {
                return ['choice' => $other, 'sameFamily' => false];
            }
        }

        return ['choice' => $choice, 'sameFamily' => true];
    }

    private function accept(int $userId, ?ResearchModelChoice $choice, ResearchWorld $world): ?ResearchModelChoice
    {
        $seen = [];
        while (null !== $choice) {
            if (isset($seen[$choice->id])) {
                return null;
            }
            $seen[$choice->id] = true;
            if ($choice->retired) {
                $choice = null !== $choice->successorId ? $this->models->byId($userId, $choice->successorId) : null;
                continue;
            }
            if (!$choice->allowed || !$choice->healthy || !$world->allows($choice->provider)) {
                return null;
            }

            return $choice;
        }

        return null;
    }

    /**
     * @return list<array{code: string, role: string, tokens: int|null}>
     */
    private function disclosures(ResearchModelChoice $reader, bool $sameFamily): array
    {
        $notes = [];
        $budget = $this->budgets->tokens('reader', $reader->contextWindow);
        if (null !== $reader->contextWindow && $budget < $this->budgets->inputCap('reader')) {
            $notes[] = ['code' => 'short_context', 'role' => 'reader', 'tokens' => $budget];
        }
        if ($sameFamily) {
            $notes[] = ['code' => 'same_family', 'role' => 'checker', 'tokens' => null];
        }

        return $notes;
    }
}
