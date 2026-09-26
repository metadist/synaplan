<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\Translator\ChatCompletionsUpstreams;
use App\Entity\Model;
use App\Entity\User;
use App\Service\MessagesGateway\MessagesGatewayConfig;

/**
 * Whether the account's default chat model can pay for app chat.
 *
 * The Desktop page must not guess from the Anthropic, OpenAI, and Google
 * keys alone. A paired computer uses this same default model, including
 * Ollama, a custom endpoint, Groq, Mistral, and the other catalog providers.
 */
final readonly class AppChatCredential
{
    public const READY = 'ready';

    public const MISSING = 'missing';

    public const UNSET = 'unset';

    public function __construct(
        private DesktopOmittedModel $desktopOmittedModel,
        private UserProviderKeyResolver $keyResolver,
        private MessagesGatewayConfig $config,
    ) {
    }

    public function forUser(User $user): string
    {
        $model = $this->desktopOmittedModel->selectedModel($user);
        if (!$model instanceof Model) {
            return self::UNSET;
        }

        $provider = strtolower(trim($model->getService()));
        if (ChatCompletionsUpstreams::isLocal($provider)) {
            return self::READY;
        }

        $userId = (int) $user->getId();
        $resolved = $this->keyResolver->resolve(
            $provider,
            $userId,
            $this->config->allowOperatorKey($userId),
        );

        return null === $resolved ? self::MISSING : self::READY;
    }
}
