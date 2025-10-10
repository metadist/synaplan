<?php

namespace App\Controller;

use App\Repository\MessageRepository;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

#[Route('/api/v1/messages', name: 'api_messages_')]
class MessageAgainController extends AbstractController
{
    public function __construct(
        private MessageRepository $messageRepository,
        private ModelRepository $modelRepository,
        private ModelConfigService $modelConfigService
    ) {}

    /**
     * Get eligible models for "Again" functionality
     * Returns models + predicted next model based on ranking
     */
    #[Route('/{messageId}/again-options', name: 'again_options', methods: ['GET'])]
    public function getAgainOptions(
        int $messageId,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Find message
        $message = $this->messageRepository->find($messageId);
        
        if (!$message) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        // Verify ownership
        if ($message->getUserId() !== $user->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get message topic/classification
        $topic = $message->getTopic();
        $capability = $this->mapTopicToCapability($topic);

        // Get all active models with this capability
        $models = $this->modelRepository->createQueryBuilder('m')
            ->where('m.active = 1')
            ->andWhere('m.tag = :capability')
            ->setParameter('capability', $capability)
            ->orderBy('m.quality', 'DESC')
            ->addOrderBy('m.rating', 'DESC')
            ->getQuery()
            ->getResult();

        // Format models for frontend
        $eligibleModels = [];
        foreach ($models as $model) {
            $eligibleModels[] = [
                'id' => $model->getId(),
                'service' => $model->getService(),
                'name' => $model->getName(),
                'providerId' => $model->getProviderId(),
                'description' => $model->getDescription(),
                'quality' => $model->getQuality(),
                'rating' => $model->getRating(),
                'tag' => strtoupper($model->getTag()),
                'label' => $this->formatModelLabel($model)
            ];
        }

        // Predicted next: highest ranked model that's different from current
        $currentModelId = $this->getCurrentModelId($message);
        $predictedNext = null;
        
        foreach ($eligibleModels as $model) {
            if ($model['id'] !== $currentModelId) {
                $predictedNext = $model;
                break;
            }
        }

        // If no different model, use first
        if (!$predictedNext && count($eligibleModels) > 0) {
            $predictedNext = $eligibleModels[0];
        }

        return $this->json([
            'success' => true,
            'message_id' => $messageId,
            'topic' => $topic,
            'capability' => $capability,
            'eligible_models' => $eligibleModels,
            'predicted_next' => $predictedNext,
            'current_model_id' => $currentModelId
        ]);
    }

    private function mapTopicToCapability(string $topic): string
    {
        return match(strtolower($topic)) {
            'mediamaker' => 'text2pic',
            'analyzefile' => 'analyze',
            'general', 'chat' => 'chat',
            default => 'chat'
        };
    }

    private function formatModelLabel(object $model): string
    {
        $name = $model->getName();
        $service = $model->getService();
        
        // Format: "Service: Model Name"
        return "{$service}: {$name}";
    }

    private function getCurrentModelId($message): ?int
    {
        // Try to get from message metadata (if stored)
        // For now, return null as we don't have this info stored yet
        // TODO: Store used model_id in BMESSAGEMETA
        return null;
    }
}

