<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;

/**
 * Dual capability gate for tool calling on /v1/chat/completions.
 *
 * Both must hold: the resolved Model has `tool_use`, and the chat provider
 * implements {@see ToolCallingChatProviderInterface} and
 * `supportsToolCalling($providerModelId)`.
 */
final readonly class OpenAiToolCallingGate
{
    public const CAPABILITY = 'synaplan:tool_use';

    public function __construct(
        private ProviderRegistry $registry,
    ) {
    }

    public function allows(Model $model): bool
    {
        if (!$model->hasFeature('tool_use')) {
            return false;
        }

        $providerId = (string) $model->getProviderId();
        if ('' === $providerId) {
            return false;
        }

        try {
            $provider = $this->registry->getChatProvider(strtolower((string) $model->getService()));
        } catch (ProviderException) {
            return false;
        }

        return $provider instanceof ToolCallingChatProviderInterface
            && $provider->supportsToolCalling($providerId);
    }
}
