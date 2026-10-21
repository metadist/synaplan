<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\Entity\Model;
use App\Repository\ModelRepository;

/**
 * Adds "you could use these instead" to a model-status snapshot.
 *
 * A healthy model gets an empty list. A model that needs attention, or that
 * was unreachable more than twice in the last hour, gets up to a handful of
 * alternatives. The snapshot is still read-only: picking one happens later,
 * from a button the person clicks.
 */
final readonly class ModelHealthSnapshotAlternatives
{
    public function __construct(
        private ModelRepository $models,
        private ModelUnavailabilityWindow $window,
        private ModelAlternatives $alternatives,
    ) {
    }

    /**
     * @param array{providers: list<array<string, mixed>>, retired?: list<array<string, mixed>>, summary?: array<string, mixed>} $snapshot
     *
     * @return array{providers: list<array<string, mixed>>, retired?: list<array<string, mixed>>, summary?: array<string, mixed>}
     */
    public function annotate(array $snapshot): array
    {
        $providers = [];
        foreach ($snapshot['providers'] as $provider) {
            $models = [];
            foreach ($provider['models'] as $row) {
                if (is_array($row)) {
                    $models[] = $this->annotateRow($row);
                }
            }
            $provider['models'] = $models;
            $providers[] = $provider;
        }
        $snapshot['providers'] = $providers;

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function annotateRow(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $count = $id > 0 ? $this->window->count($id) : 0;
        $row['unavailableCount'] = $count;

        $needsOffer = (bool) ($row['needsAttention'] ?? false) || $count > ModelUnavailabilityWindow::OFFER_AFTER;
        if (!$needsOffer || $id < 1) {
            $row['alternatives'] = [];
            $row['defaultCapability'] = null;

            return $row;
        }

        $model = $this->models->find($id);
        if (!$model instanceof Model) {
            $row['alternatives'] = [];
            $row['defaultCapability'] = null;

            return $row;
        }

        $row['alternatives'] = $this->alternatives->forModel($model);
        $row['defaultCapability'] = $this->alternatives->defaultCapability($model);

        return $row;
    }
}
