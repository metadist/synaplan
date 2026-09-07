<?php

declare(strict_types=1);

namespace App\Service\Runtime;

use App\Entity\Prompt;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\PromptService;

/**
 * Builds today's classification-derived runtime (no assistant involved).
 */
final readonly class RuntimeProfileFactory
{
    public function __construct(
        private ModelConfigService $modelConfigService,
        private PromptService $promptService,
    ) {
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function forUserDefaults(User $user, array $classification): RuntimeProfile
    {
        $userId = (int) $user->getId();
        $topic = is_string($classification['topic'] ?? null) ? $classification['topic'] : 'general';
        $language = is_string($classification['language'] ?? null) ? $classification['language'] : 'en';

        $promptData = $this->promptService->getPromptWithMetadata($topic, $userId, $language);
        $prompt = is_array($promptData) ? ($promptData['prompt'] ?? null) : null;
        $metadata = is_array($promptData) && is_array($promptData['metadata'] ?? null) ? $promptData['metadata'] : [];

        $promptId = $prompt instanceof Prompt ? $prompt->getId() : null;
        $systemPrompt = $prompt instanceof Prompt ? $prompt->getPrompt() : null;

        $chatModel = isset($classification['model_id']) && $classification['model_id']
            ? (int) $classification['model_id']
            : (isset($classification['override_model_id']) && (int) $classification['override_model_id'] > 0
                ? (int) $classification['override_model_id']
                : (isset($metadata['aiModel']) && (int) $metadata['aiModel'] > 0
                    ? (int) $metadata['aiModel']
                    : $this->modelConfigService->getDefaultModel('CHAT', $userId)));

        $ragScopes = [];
        $ragGroupKey = is_string($classification['rag_group_key'] ?? null) ? $classification['rag_group_key'] : null;
        if (null === $ragGroupKey && 'general' !== $topic) {
            $ragGroupKey = 'TASKPROMPT:'.$topic;
        }
        if (null !== $ragGroupKey) {
            $ragScopes[] = ['ownerId' => $userId, 'groupKey' => $ragGroupKey];
        }

        $toolFlags = [];
        foreach (['tool_internet', 'tool_files', 'tool_mcp'] as $flag) {
            if (array_key_exists($flag, $metadata)) {
                $toolFlags[$flag] = $metadata[$flag];
            }
        }

        return new RuntimeProfile(
            promptId: $promptId,
            promptTopic: $topic,
            systemPrompt: $systemPrompt,
            modelIds: ['chat' => $chatModel],
            ragScopes: $ragScopes,
            toolFlags: $toolFlags,
            skillAllow: null,
            skillDeny: null,
            parameters: [],
            agentId: null,
            agentVersionId: null,
            notes: [],
        );
    }
}
