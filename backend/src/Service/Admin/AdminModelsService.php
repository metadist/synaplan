<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminModelsService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ModelRepository $modelRepository,
        private ConfigRepository $configRepository,
        private ModelImportService $importService,
        private ModelSqlValidator $sqlValidator,
    ) {
    }

    /**
     * @return Model[]
     */
    public function listModels(): array
    {
        return $this->modelRepository->findBy([], ['id' => 'ASC']);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createModel(array $data): Model
    {
        $service = trim((string) ($data['service'] ?? ''));
        $tag = strtolower(trim((string) ($data['tag'] ?? '')));
        $providerId = trim((string) ($data['providerId'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $service || '' === $tag || '' === $providerId || '' === $name) {
            throw new \InvalidArgumentException('Missing required fields: service, tag, providerId, name');
        }

        $existing = $this->modelRepository->findOneBy([
            'service' => $service,
            'tag' => $tag,
            'providerId' => $providerId,
        ]);
        if ($existing) {
            throw new ModelConflictException('Model already exists for (service+tag+providerId)');
        }

        $model = (new Model())
            ->setService($service)
            ->setTag($tag)
            ->setProviderId($providerId)
            ->setName($name)
            ->setSelectable((int) ($data['selectable'] ?? 1))
            ->setActive((int) ($data['active'] ?? 1))
            ->setPriceIn((float) ($data['priceIn'] ?? 0.0))
            ->setInUnit((string) ($data['inUnit'] ?? 'per1M'))
            ->setPriceOut((float) ($data['priceOut'] ?? 0.0))
            ->setOutUnit((string) ($data['outUnit'] ?? 'per1M'))
            ->setQuality((float) ($data['quality'] ?? 7.0))
            ->setRating((float) ($data['rating'] ?? 0.5));

        if (array_key_exists('description', $data)) {
            $model->setDescription(null !== $data['description'] ? (string) $data['description'] : null);
        }
        if (isset($data['json']) && is_array($data['json'])) {
            $model->setJson($data['json']);
        }

        $this->em->persist($model);
        $this->em->flush();

        return $model;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateModel(int $id, array $data): Model
    {
        $model = $this->modelRepository->find($id);
        if (!$model) {
            throw new ModelNotFoundException('Model not found');
        }

        $newService = array_key_exists('service', $data) ? trim((string) $data['service']) : $model->getService();
        $newTag = array_key_exists('tag', $data) ? strtolower(trim((string) $data['tag'])) : $model->getTag();
        $newProviderId = array_key_exists('providerId', $data) ? trim((string) $data['providerId']) : $model->getProviderId();

        $conflict = $this->modelRepository->findOneBy([
            'service' => $newService,
            'tag' => $newTag,
            'providerId' => $newProviderId,
        ]);
        if ($conflict && $conflict->getId() !== $model->getId()) {
            throw new ModelConflictException('Another model already exists for (service+tag+providerId)');
        }

        $model->setService($newService);
        $model->setTag($newTag);
        $model->setProviderId($newProviderId);

        if (array_key_exists('name', $data)) {
            $model->setName((string) $data['name']);
        }
        if (array_key_exists('selectable', $data)) {
            $model->setSelectable((int) $data['selectable']);
        }
        if (array_key_exists('active', $data)) {
            $model->setActive((int) $data['active']);
        }
        if (array_key_exists('priceIn', $data)) {
            $model->setPriceIn((float) $data['priceIn']);
        }
        if (array_key_exists('inUnit', $data)) {
            $model->setInUnit((string) $data['inUnit']);
        }
        if (array_key_exists('priceOut', $data)) {
            $model->setPriceOut((float) $data['priceOut']);
        }
        if (array_key_exists('outUnit', $data)) {
            $model->setOutUnit((string) $data['outUnit']);
        }
        if (array_key_exists('quality', $data)) {
            $model->setQuality((float) $data['quality']);
        }
        if (array_key_exists('rating', $data)) {
            $model->setRating((float) $data['rating']);
        }
        if (array_key_exists('description', $data)) {
            $model->setDescription(null !== $data['description'] ? (string) $data['description'] : null);
        }
        if (array_key_exists('json', $data) && is_array($data['json'])) {
            $model->setJson($data['json']);
        }

        $this->em->flush();

        return $model;
    }

    public function deleteModel(int $id): void
    {
        $model = $this->modelRepository->find($id);
        if (!$model) {
            throw new ModelNotFoundException('Model not found');
        }

        $usageCount = $this->configRepository->count([
            'group' => 'DEFAULTMODEL',
            'value' => (string) $id,
        ]);
        if ($usageCount > 0) {
            throw new ModelConflictException('Model is referenced by DEFAULTMODEL configuration. Change defaults first before deleting.');
        }

        $this->em->remove($model);
        $this->em->flush();
    }

    /**
     * @param string[] $urls
     *
     * @return array{sql: string, provider: string|null, model: string|null, validation: array{ok: bool, errors: string[], statements: string[]}}
     */
    public function generateImportPreview(int $userId, array $urls, string $textDump, bool $allowDelete = false): array
    {
        $preview = $this->importService->generateSqlPreview($userId, $urls, $textDump, $allowDelete);
        $validated = $this->sqlValidator->validateAndSplit($preview['sql']);

        return [
            'sql' => $preview['sql'],
            'provider' => $preview['provider'],
            'model' => $preview['model'],
            'validation' => [
                'ok' => empty($validated['errors']),
                'errors' => $validated['errors'],
                'statements' => $validated['statements'],
            ],
        ];
    }

    /**
     * @return array{applied: int, statements: string[]}
     */
    public function applyImportSql(string $sql): array
    {
        return $this->importService->applySql($sql);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeModel(Model $model): array
    {
        return [
            'id' => $model->getId(),
            'service' => $model->getService(),
            'tag' => $model->getTag(),
            'providerId' => $model->getProviderId(),
            'name' => $model->getName(),
            'selectable' => $model->getSelectable(),
            'active' => $model->getActive(),
            'priceIn' => $model->getPriceIn(),
            'inUnit' => $model->getInUnit(),
            'priceOut' => $model->getPriceOut(),
            'outUnit' => $model->getOutUnit(),
            'quality' => $model->getQuality(),
            'rating' => $model->getRating(),
            'description' => $model->getDescription(),
            'json' => $model->getJson(),
            'isSystemModel' => $model->isSystemModel(),
        ];
    }
}
