<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\PlainText;

/**
 * The person's replacement for a plan that is waiting. A point named by id
 * stays, and may get a new title. A point with only a title is added. A
 * point that is not named is dropped. The plan must still have one point.
 */
final class ResearchPlanEdit
{
    /**
     * @param array<string, mixed> $body
     */
    public static function apply(EvidenceLedger $ledger, array $body): void
    {
        $rows = $body['points'] ?? null;
        if (!is_array($rows) || [] === $rows) {
            throw new ResearchRejected(400, 'invalid', 'points', 'Name the points the plan should keep.');
        }
        $known = [];
        foreach ($ledger->points() as $point) {
            $known[$point->id] = $point->title;
        }
        $keep = [];
        $add = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ResearchRejected(400, 'invalid', 'points', 'Each point needs a title or an id from this plan.');
            }
            $id = $row['id'] ?? null;
            $title = is_string($row['title'] ?? null) ? PlainText::line($row['title'], 300) : '';
            if (is_string($id) && '' !== $id) {
                if (!isset($known[$id]) || isset($keep[$id])) {
                    throw new ResearchRejected(400, 'invalid', 'points', 'That point is not in this plan.');
                }
                $keep[$id] = '' !== $title ? $title : $known[$id];
                continue;
            }
            if ('' === $title) {
                throw new ResearchRejected(400, 'invalid', 'points', 'Each point needs a title or an id from this plan.');
            }
            $add[] = $title;
        }
        if ([] === $keep && [] === $add) {
            throw new ResearchRejected(400, 'invalid', 'points', 'The plan needs at least one point.');
        }
        foreach (array_keys($known) as $id) {
            if (!isset($keep[$id])) {
                $ledger->dropPoint($id);
            }
        }
        foreach ($keep as $id => $title) {
            $ledger->renamePoint($id, $title);
        }
        foreach ($add as $title) {
            $ledger->addPoint($title);
        }
    }
}
