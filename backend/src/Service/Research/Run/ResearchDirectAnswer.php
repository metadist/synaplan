<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;
use App\Service\Research\Run\Stage\ResearchCallUsage;
use Psr\Log\LoggerInterface;

/**
 * The plain chat answer for a run that ended with "Answer now" before any
 * source was read: the question goes to the person's chat model once,
 * without research. Null when no answer could be written.
 */
final readonly class ResearchDirectAnswer
{
    public function __construct(
        private AiFacade $ai,
        private ModelConfigService $models,
        private LoggerInterface $logger,
    ) {
    }

    public function answer(ResearchRunState $state): ?string
    {
        $userId = $state->ref->userId;
        try {
            $modelId = $this->models->getDefaultModel('CHAT', $userId);
            $provider = null !== $modelId ? $this->models->getProviderForModel($modelId) : null;
            $model = null !== $modelId ? $this->models->getModelName($modelId) : null;
            if (null === $provider || '' === $provider || null === $model || '' === $model) {
                return null;
            }
            $response = $this->ai->chat([['role' => 'user', 'content' => $state->ref->question]], $userId, [
                'provider' => $provider,
                'model' => $model,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('The direct answer after Answer now was not written', [
                'run_id' => $state->ref->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        $text = trim((string) ($response['content'] ?? ''));
        $usage = ResearchCallUsage::fromResponse($response, 0.0);
        if ($usage->totalTokens > 0) {
            $state->spend->add('answer', $provider.':'.$model, $usage);
        }

        return '' === $text ? null : $text;
    }
}
