<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\Exception\EnhancePromptMissingException;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use Psr\Log\LoggerInterface;

/**
 * Rewrites what a user is about to send into a clearer prompt: the
 * tools:enhance prompt, the user's chat model (as the chat answers), one
 * call. An answer that refuses or explains instead of rewriting is no
 * enhancement.
 */
final readonly class InputEnhancer
{
    public function __construct(
        private PromptService $promptService,
        private ModelConfigService $modelConfigService,
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null the enhanced text, or null when the answer was no rewrite
     *
     * @throws EnhancePromptMissingException
     * @throws ProviderException
     */
    public function enhance(User $user, string $inputText): ?string
    {
        $promptData = $this->promptService->getPromptWithMetadata('tools:enhance', 0, 'en');
        if (!$promptData) {
            throw new EnhancePromptMissingException();
        }

        $systemPrompt = $promptData['prompt']->getPrompt();
        $this->logger->info('Enhancement prompt loaded', [
            'prompt_id' => $promptData['prompt']->getId(),
            'prompt_length' => strlen($systemPrompt),
        ]);

        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
        $provider = null;
        $modelName = null;
        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            $this->logger->info('Enhancement model resolved', [
                'model_id' => $modelId,
                'provider' => $provider,
                'model' => $modelName,
            ]);
        }

        $response = $this->aiFacade->chat(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $inputText],
            ],
            $user->getId(),
            [
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0.7,
            ]
        );

        $this->logger->info('Enhancement response received', [
            'response_length' => strlen($response['content'] ?? ''),
        ]);

        $enhancedText = trim($response['content'] ?? $inputText);
        if (EnhanceOutputGuard::isRefusalOrNonEnhancement($inputText, $enhancedText)) {
            $this->logger->info('Enhancement output treated as refusal or explanation', [
                'user_id' => $user->getId(),
                'input_length' => strlen($inputText),
                'output_length' => strlen($enhancedText),
            ]);

            return null;
        }

        return $enhancedText;
    }
}
