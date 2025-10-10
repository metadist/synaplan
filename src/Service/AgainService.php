<?php

namespace App\Service;

use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use Psr\Log\LoggerInterface;

/**
 * Again Service
 * 
 * Handles "Again" functionality:
 * - Get eligible models for a topic/tag
 * - Get predicted next model (one rank down from current)
 * - Format models for frontend display
 * 
 * Logic from legacy AgainLogic class
 */
class AgainService
{
    public function __construct(
        private ModelRepository $modelRepository,
        private ConfigRepository $configRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Get eligible models for a tag/capability
     * 
     * @param string $tag Model tag (e.g., 'chat', 'text2pic', 'pic2text')
     * @param int|null $userId User ID for personalized rating filter
     * @return array Array of models with formatted data
     */
    public function getEligibleModels(string $tag, ?int $userId = null): array
    {
        $minRating = $this->getMinRating($userId);
        
        $models = $this->modelRepository->findByTag($tag, true, $minRating);

        $this->logger->info('AgainService: Retrieved eligible models', [
            'tag' => $tag,
            'count' => count($models),
            'min_rating' => $minRating
        ]);

        return array_map(fn(Model $m) => $this->formatModel($m), $models);
    }

    /**
     * Get predicted next model (one rank down from current)
     * 
     * @param array $eligibleModels Array of eligible models
     * @param int|null $currentModelId Current model ID
     * @return array|null Predicted next model data or null
     */
    public function getPredictedNext(array $eligibleModels, ?int $currentModelId): ?array
    {
        if (empty($eligibleModels)) {
            return null;
        }

        // If no current model, return first one
        if (!$currentModelId) {
            return $eligibleModels[0] ?? null;
        }

        // Find current model index
        $currentIndex = null;
        foreach ($eligibleModels as $index => $model) {
            if ($model['id'] === $currentModelId) {
                $currentIndex = $index;
                break;
            }
        }

        // If current model not found or is last, return first
        if ($currentIndex === null || $currentIndex >= count($eligibleModels) - 1) {
            return $eligibleModels[0] ?? null;
        }

        // Return next model in list (one rank down)
        return $eligibleModels[$currentIndex + 1] ?? $eligibleModels[0];
    }

    /**
     * Resolve tag/capability from topic
     * Maps BTOPIC to BTAG for model lookup
     * 
     * @param string $topic Topic from message (e.g., 'general', 'mediamaker', 'analyzefile')
     * @return string Model tag (e.g., 'chat', 'text2pic', 'pic2text')
     */
    public function resolveTagFromTopic(string $topic): string
    {
        // Mapping from BTOPIC to BTAG
        $topicToTagMap = [
            'general' => 'chat',
            'mediamaker' => 'text2pic', // or 'text2vid' or 'text2sound' - needs context
            'analyzefile' => 'pic2text',
            'tools:sort' => 'chat',
            'tools:pic' => 'text2pic',
            'tools:vid' => 'text2vid',
            'tools:search' => 'chat',
            'tools:lang' => 'chat',
            'tools:filesort' => 'vectorize',
        ];

        return $topicToTagMap[$topic] ?? 'chat';
    }

    /**
     * Get minimum rating from config
     * 
     * @param int|null $userId User ID for user-specific config
     * @return float|null Minimum rating or null
     */
    private function getMinRating(?int $userId): ?float
    {
        try {
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId ?? 0,
                'group' => 'SYSTEM_FLAGS',
                'setting' => 'MIN_MODEL_RATING'
            ]);

            if ($config && is_numeric($config->getValue())) {
                return (float) $config->getValue();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AgainService: Failed to get min rating', [
                'error' => $e->getMessage()
            ]);
        }

        return null; // No filtering
    }

    /**
     * Format model for frontend
     */
    private function formatModel(Model $model): array
    {
        return [
            'id' => $model->getId(),
            'service' => $model->getService(),
            'name' => $model->getName(),
            'tag' => $model->getTag(),
            'providerId' => $model->getProviderId(),
            'quality' => $model->getQuality(),
            'rating' => $model->getRating(),
            'priceIn' => $model->getPriceIn(),
            'priceOut' => $model->getPriceOut(),
            'selectable' => $model->isSelectable(),
        ];
    }
}

