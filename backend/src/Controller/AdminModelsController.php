<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Admin\ModelImportService;
use App\Service\Admin\ModelSqlValidator;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/admin/models')]
#[OA\Tag(name: 'Admin Models')]
final class AdminModelsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModelRepository $modelRepository,
        private readonly ConfigRepository $configRepository,
        private readonly ModelImportService $importService,
        private readonly ModelSqlValidator $sqlValidator,
    ) {
    }

    #[Route('', name: 'admin_models_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $models = $this->modelRepository->findBy([], ['id' => 'ASC']);

        return $this->json([
            'success' => true,
            'models' => array_map([$this, 'serializeModel'], $models),
        ]);
    }

    #[Route('', name: 'admin_models_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $service = trim((string) ($data['service'] ?? ''));
        $tag = strtolower(trim((string) ($data['tag'] ?? '')));
        $providerId = trim((string) ($data['providerId'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $service || '' === $tag || '' === $providerId || '' === $name) {
            return $this->json(['error' => 'Missing required fields: service, tag, providerId, name'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->modelRepository->findOneBy([
            'service' => $service,
            'tag' => $tag,
            'providerId' => $providerId,
        ]);
        if ($existing) {
            return $this->json(['error' => 'Model already exists for (service+tag+providerId)'], Response::HTTP_CONFLICT);
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
            $model->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (isset($data['json']) && is_array($data['json'])) {
            $model->setJson($data['json']);
        }

        $this->em->persist($model);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'model' => $this->serializeModel($model),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'admin_models_update', methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        /** @var Model|null $model */
        $model = $this->modelRepository->find($id);
        if (!$model) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Allow editing of the unique key fields, but check for conflicts.
        $newService = array_key_exists('service', $data) ? trim((string) $data['service']) : $model->getService();
        $newTag = array_key_exists('tag', $data) ? strtolower(trim((string) $data['tag'])) : $model->getTag();
        $newProviderId = array_key_exists('providerId', $data) ? trim((string) $data['providerId']) : $model->getProviderId();

        $conflict = $this->modelRepository->findOneBy([
            'service' => $newService,
            'tag' => $newTag,
            'providerId' => $newProviderId,
        ]);
        if ($conflict && $conflict->getId() !== $model->getId()) {
            return $this->json(['error' => 'Another model already exists for (service+tag+providerId)'], Response::HTTP_CONFLICT);
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
            $model->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (array_key_exists('json', $data) && is_array($data['json'])) {
            $model->setJson($data['json']);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'model' => $this->serializeModel($model),
        ]);
    }

    #[Route('/{id}', name: 'admin_models_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        /** @var Model|null $model */
        $model = $this->modelRepository->find($id);
        if (!$model) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        // Prevent deleting models that are still referenced by DEFAULTMODEL configs
        $usageCount = $this->configRepository->count([
            'group' => 'DEFAULTMODEL',
            'value' => (string) $id,
        ]);
        if ($usageCount > 0) {
            return $this->json([
                'error' => 'Model is referenced by DEFAULTMODEL configuration. Change defaults first before deleting.',
            ], Response::HTTP_CONFLICT);
        }

        $this->em->remove($model);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/import/preview', name: 'admin_models_import_preview', methods: ['POST'])]
    public function importPreview(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $urls = $data['urls'] ?? [];
        $textDump = (string) ($data['textDump'] ?? '');
        $allowDelete = (bool) ($data['allowDelete'] ?? false);

        if (!is_array($urls)) {
            return $this->json(['error' => 'urls must be an array'], Response::HTTP_BAD_REQUEST);
        }

        $preview = $this->importService->generateSqlPreview($user->getId(), $urls, $textDump, $allowDelete);

        $validated = $this->sqlValidator->validateAndSplit($preview['sql']);

        return $this->json([
            'success' => true,
            'sql' => $preview['sql'],
            'ai' => [
                'provider' => $preview['provider'],
                'model' => $preview['model'],
            ],
            'validation' => [
                'ok' => empty($validated['errors']),
                'errors' => $validated['errors'],
                'statements' => $validated['statements'],
            ],
        ]);
    }

    #[Route('/import/apply', name: 'admin_models_import_apply', methods: ['POST'])]
    public function importApply(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user || !$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $sql = (string) ($data['sql'] ?? '');
        if ('' === trim($sql)) {
            return $this->json(['error' => 'sql is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->importService->applySql($sql);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'applied' => $result['applied'],
            'statements' => $result['statements'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeModel(Model $model): array
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


