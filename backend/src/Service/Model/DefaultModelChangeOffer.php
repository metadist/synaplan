<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\AI\Health\ModelAlternatives;
use App\AI\Health\ModelUnavailabilityWindow;
use App\Entity\Model;
use App\Repository\ModelRepository;

/**
 * Defaults the signed-in person may want to change, because that model was
 * unreachable more than twice in the last hour.
 *
 * Embedding is left out: changing it rebuilds search, and that screen already
 * explains the cost. A locked default is left out too — the administrator
 * chose it, and a button here would only end in a refusal.
 *
 * This builds the offer. It does not save anything.
 */
final readonly class DefaultModelChangeOffer
{
    public function __construct(
        private ModelDefaultsService $defaults,
        private ModelRepository $models,
        private ModelUnavailabilityWindow $window,
        private ModelAlternatives $alternatives,
    ) {
    }

    /**
     * @return array{offers: list<array{capability: string, modelId: int, modelName: string, unavailableCount: int, alternatives: list<array{id: int, name: string, service: string}>}>}
     */
    public function forUser(int $userId): array
    {
        $reported = $this->defaults->userDefaults($userId);
        $offers = [];

        foreach ($reported['defaults'] as $capability => $modelId) {
            if ('VECTORIZE' === $capability || !is_int($modelId) || $modelId < 1) {
                continue;
            }
            if (($reported['locked'][$capability] ?? false) === true) {
                continue;
            }
            if (!$this->window->offersChange($modelId)) {
                continue;
            }

            $model = $this->models->find($modelId);
            if (!$model instanceof Model) {
                continue;
            }

            $offers[] = [
                'capability' => $capability,
                'modelId' => $modelId,
                'modelName' => $model->getName(),
                'unavailableCount' => $this->window->count($modelId),
                'alternatives' => $this->alternatives->forModel($model, $userId, $capability),
            ];
        }

        return ['offers' => $offers];
    }
}
