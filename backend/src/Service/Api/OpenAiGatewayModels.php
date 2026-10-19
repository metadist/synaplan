<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;

/**
 * Resolves the `model` of an OpenAI-compatible request for one capability: a
 * named model must be an active row with that tag (BPROVID is not unique
 * across tags), otherwise the caller's default model for the capability.
 */
final readonly class OpenAiGatewayModels
{
    public function __construct(
        private ModelRepository $models,
        private ModelConfigService $modelConfig,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function modelField(array $body): ?string
    {
        $model = $body['model'] ?? null;
        if (null !== $model && !\is_string($model)) {
            throw OpenAiGatewayException::invalid('model must be a string', 'invalid_model');
        }

        return null === $model || '' === trim($model) ? null : trim($model);
    }

    /**
     * @param string $tag        BMODELS.BTAG, e.g. `vectorize`
     * @param string $capability DEFAULTMODEL capability, e.g. `VECTORIZE`
     */
    public function resolve(string $tag, string $capability, ?string $requested, int $userId): Model
    {
        if (null !== $requested) {
            $model = $this->models->findActiveByTagAndIdentity($tag, $requested);
        } else {
            $defaultId = $this->modelConfig->getDefaultModel($capability, $userId);
            $model = null !== $defaultId ? $this->models->find($defaultId) : null;
        }

        if ($model instanceof Model && $model->isRetired()) {
            $replacementId = $this->modelConfig->replacementForRetiredModel((int) $model->getId(), $capability, $userId);
            $model = null !== $replacementId ? $this->models->find($replacementId) : null;
        }

        if (!$model instanceof Model || 1 !== $model->getActive() || $tag !== $model->getTag()) {
            throw OpenAiGatewayException::modelNotFound($requested, $tag);
        }

        return $model;
    }
}
