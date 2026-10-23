<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

use App\AI\Service\AiFacade;
use App\Service\ModelConfigService;

/**
 * The evaluation account's default chat model, including native tool calls.
 */
final readonly class FacadeSpikeChat implements SpikeChat
{
    public function __construct(
        private AiFacade $ai,
        private ModelConfigService $models,
    ) {
    }

    public function complete(array $messages, array $tools, int $userId): SpikeTurn
    {
        $options = $this->options($userId);
        if ([] !== $tools) {
            $options['tools'] = $tools;
            $options['tool_choice'] = 'auto';
        }
        $response = $this->ai->chat($messages, $userId, $options);

        return SpikeTurn::fromProvider($response);
    }

    /**
     * @return array{provider: string, model: string}
     */
    private function options(int $userId): array
    {
        $modelId = $this->models->getDefaultModel('CHAT', $userId);
        $provider = null !== $modelId ? $this->models->getProviderForModel($modelId) : null;
        $model = null !== $modelId ? $this->models->getModelName($modelId) : null;
        if (null === $provider || '' === $provider || null === $model || '' === $model) {
            throw new \RuntimeException('The evaluation account has no default chat model. Nothing was sent.');
        }

        return ['provider' => $provider, 'model' => $model];
    }
}
