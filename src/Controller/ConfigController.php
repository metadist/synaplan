<?php

namespace App\Controller;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

#[Route('/api/v1/config', name: 'api_config_')]
class ConfigController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository
    ) {}

    /**
     * Get all available models (all active models for all capabilities)
     * User can choose ANY model for ANY capability (cross-capability usage)
     */
    #[Route('/models', name: 'models_list', methods: ['GET'])]
    public function getModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get all active models sorted by quality
        $models = $this->modelRepository->findBy(['active' => 1], ['quality' => 'DESC', 'rating' => 'DESC']);
        
        // All capabilities get ALL models (user can choose any model for any task)
        $modelList = [];
        foreach ($models as $model) {
            $modelList[] = [
                'id' => $model->getId(),
                'service' => $model->getService(),
                'name' => $model->getName(),
                'providerId' => $model->getProviderId(),
                'description' => $model->getDescription(),
                'quality' => $model->getQuality(),
                'rating' => $model->getRating(),
                'tag' => strtoupper($model->getTag()),
                'isSystemModel' => $model->isSystemModel(),
                'features' => $model->getFeatures()
            ];
        }
        
        // Return same list for all capabilities (cross-capability)
        $grouped = [
            'SORT' => $modelList,
            'CHAT' => $modelList,
            'VECTORIZE' => $modelList,
            'PIC2TEXT' => $modelList,
            'TEXT2PIC' => $modelList,
            'SOUND2TEXT' => $modelList,
            'TEXT2SOUND' => $modelList,
            'ANALYZE' => $modelList
        ];

        return $this->json([
            'success' => true,
            'models' => $grouped
        ]);
    }

    /**
     * Get current default model configuration for user
     */
    #[Route('/models/defaults', name: 'models_defaults', methods: ['GET'])]
    public function getDefaultModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = $user->getId();
        $capabilities = ['SORT', 'CHAT', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE'];
        
        $defaults = [];

        foreach ($capabilities as $capability) {
            // Try user-specific config first
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => $capability
            ]);

            // Fall back to global config
            if (!$config) {
                $config = $this->configRepository->findOneBy([
                    'ownerId' => 0,
                    'group' => 'DEFAULTMODEL',
                    'setting' => $capability
                ]);
            }

            $defaults[$capability] = $config ? (int) $config->getValue() : null;
        }

        return $this->json([
            'success' => true,
            'defaults' => $defaults
        ]);
    }

    /**
     * Save default model configuration for user
     */
    #[Route('/models/defaults', name: 'models_defaults_save', methods: ['POST'])]
    public function saveDefaultModels(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['defaults']) || !is_array($data['defaults'])) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        $userId = $user->getId();
        $validCapabilities = ['SORT', 'CHAT', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE'];

        foreach ($data['defaults'] as $capability => $modelId) {
            if (!in_array($capability, $validCapabilities)) {
                continue;
            }

            // Validate model exists - allow any active model for any capability (cross-capability)
            $model = $this->modelRepository->find($modelId);
            if (!$model) {
                return $this->json([
                    'error' => "Model {$modelId} not found"
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // Check if model is active
            if ($model->getActive() !== 1) {
                return $this->json([
                    'error' => "Model {$modelId} is not active"
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if user-specific config exists
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => $capability
            ]);

            if (!$config) {
                // Create new user-specific config
                $config = new Config();
                $config->setOwnerId($userId);
                $config->setGroup('DEFAULTMODEL');
                $config->setSetting($capability);
            }

            $config->setValue((string) $modelId);
            $this->em->persist($config);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Default models saved successfully'
        ]);
    }
}

