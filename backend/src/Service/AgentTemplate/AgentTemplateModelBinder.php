<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;

/**
 * Pins a template's model slots to this person's default for that capability.
 * Templates never name a model.
 */
final readonly class AgentTemplateModelBinder
{
    public function __construct(
        private ModelConfigService $defaults,
        private ModelRepository $models,
    ) {
    }

    /**
     * @param array<string, mixed>                                                                $definition
     * @param list<array{kind: string, value: string|null, hintKey: string|null, optional: bool}> $requires
     *
     * @return array<string, mixed>
     */
    public function bind(array $definition, array $requires, int $userId): array
    {
        $models = is_array($definition['models'] ?? null) ? $definition['models'] : [];
        foreach ($requires as $requirement) {
            if ('capability' !== $requirement['kind']) {
                continue;
            }
            $slot = $this->slot((string) ($requirement['value'] ?? ''));
            if (null === $slot || (is_string($models[$slot] ?? null) && '' !== $models[$slot])) {
                continue;
            }
            $key = $this->defaultKey((string) $requirement['value'], $userId);
            if (null !== $key) {
                $models[$slot] = $key;
            }
        }
        $definition['models'] = $models;

        return $definition;
    }

    private function slot(string $capability): ?string
    {
        return match (strtolower($capability)) {
            'chat' => 'chat',
            'pic2text' => 'vision',
            'vectorize' => 'vectorize',
            default => null,
        };
    }

    private function defaultKey(string $capability, int $userId): ?string
    {
        $id = $this->defaults->getDefaultModel(strtoupper($capability), $userId);
        if (null === $id || $id < 1) {
            return null;
        }
        $model = $this->models->find($id);
        if (!$model instanceof Model) {
            return null;
        }
        $key = strtolower($model->getService())
            .':'.strtolower(str_replace(':', '-', $model->getProviderId()))
            .':'.strtolower($model->getTag());
        if (ModelCatalog::findBidByKey($key) !== $id) {
            return null;
        }

        return $key;
    }
}
