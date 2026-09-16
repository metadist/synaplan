<?php

declare(strict_types=1);

namespace App\Service\Stt;

use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use App\Service\Stt\Exception\SttModelNotFoundException;

/**
 * Resolves a SOUND2TEXT catalog row for an external transcription request.
 *
 * @phpstan-type ResolvedSttModel array{
 *     provider: string,
 *     providerModelId: string,
 *     displayModel: string,
 *     model_id: int
 * }
 */
final readonly class SttModelResolver
{
    public const TAG = 'sound2text';

    public function __construct(
        private ModelRepository $modelRepository,
        private ModelConfigService $modelConfigService,
    ) {
    }

    /**
     * @return ResolvedSttModel
     */
    public function resolve(?string $modelString, int $userId): array
    {
        if (null !== $modelString && '' !== trim($modelString)) {
            $model = $this->modelRepository->findActiveByTagAndIdentity(self::TAG, trim($modelString));
            if (null !== $model) {
                return $this->toResolved($model);
            }

            throw new SttModelNotFoundException($modelString);
        }

        $default = $this->modelConfigService->resolveSttDefault($userId);
        if (null !== $default['model_id']) {
            $model = $this->modelRepository->find($default['model_id']);
            if ($model instanceof Model && 1 === $model->getActive() && self::TAG === $model->getTag()) {
                return $this->toResolved($model);
            }
        }

        $fallback = $this->modelRepository->findActiveByTag(self::TAG);
        if ([] !== $fallback) {
            return $this->toResolved($fallback[0]);
        }

        throw new SttModelNotFoundException($modelString);
    }

    /**
     * @return list<array{id: string, object: string, created: int, owned_by: string, tag: string}>
     */
    public function listModels(): array
    {
        $data = [];
        foreach ($this->modelRepository->findActiveByTag(self::TAG) as $model) {
            $data[] = [
                'id' => $model->getProviderId() ?: $model->getName(),
                'object' => 'model',
                'created' => 1700000000,
                'owned_by' => strtolower($model->getService()),
                'tag' => $model->getTag(),
            ];
        }

        return $data;
    }

    /**
     * @return ResolvedSttModel
     */
    private function toResolved(Model $model): array
    {
        $providerModelId = $model->getProviderId() ?: $model->getName();

        return [
            'provider' => strtolower($model->getService()),
            'providerModelId' => $providerModelId,
            'displayModel' => $providerModelId,
            'model_id' => (int) $model->getId(),
        ];
    }
}
