<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\Model;
use App\Entity\Prompt;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Repository\AgentRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\ModelConfigService;
use App\Service\Runtime\RuntimeProfile;

/**
 * Turns an assistant id into a {@see RuntimeProfile} the chat path can consume.
 *
 * S1: owner-only draft. Published versions arrive in S3; shared folders in S4.
 */
final readonly class AgentRuntimeResolver
{
    public function __construct(
        private AgentRepository $agents,
        private PromptRepository $prompts,
        private ModelRepository $models,
        private ModelConfigService $modelConfigService,
        private AgentDefinitionValidator $validator,
    ) {
    }

    public function resolve(int $agentId, User $user, bool $draft = true): RuntimeProfile
    {
        $agent = $this->agents->find($agentId);
        if (!$agent instanceof Agent || $agent->getOwnerId() !== (int) $user->getId()) {
            throw AgentNotAccessibleException::forId($agentId);
        }

        $definition = $this->validator->validate($agent->getDraft());
        $prompt = $this->prompts->find($agent->getPromptId());
        $topic = $prompt instanceof Prompt ? $prompt->getTopic() : 'agent:'.$agent->getSlug();
        $systemPrompt = $prompt instanceof Prompt ? $prompt->getPrompt() : AgentService::DEFAULT_INSTRUCTION;

        $notes = [];
        $modelIds = $this->resolveModels($definition, (int) $user->getId(), $notes);

        $ragScopes = [];
        if ($definition->ownFolderEnabled()) {
            $ragScopes[] = [
                'ownerId' => $agent->getOwnerId(),
                'groupKey' => 'TASKPROMPT:agent:'.$agent->getSlug(),
            ];
        }

        $tools = $definition->tools();
        $toolFlags = [
            'tool_internet' => (bool) ($tools['internet'] ?? true),
            'tool_files' => (bool) ($tools['files'] ?? true),
        ];
        if (isset($tools['mcpServers']) && is_array($tools['mcpServers']) && [] !== $tools['mcpServers']) {
            $toolFlags['tool_mcp'] = true;
            $toolFlags['mcp_servers'] = $tools['mcpServers'];
        }

        $skills = $definition->skills();
        $skillAllow = isset($skills['allow']) && is_array($skills['allow']) && [] !== $skills['allow']
            ? array_values(array_filter($skills['allow'], 'is_string'))
            : null;
        $skillDeny = isset($skills['deny']) && is_array($skills['deny']) && [] !== $skills['deny']
            ? array_values(array_filter($skills['deny'], 'is_string'))
            : null;

        return new RuntimeProfile(
            promptId: $agent->getPromptId(),
            promptTopic: $topic,
            systemPrompt: $systemPrompt,
            modelIds: $modelIds,
            ragScopes: $ragScopes,
            toolFlags: $toolFlags,
            skillAllow: $skillAllow,
            skillDeny: $skillDeny,
            parameters: $definition->parameters(),
            agentId: $agent->getId(),
            agentVersionId: $draft ? null : $agent->getPublishedVersionId(),
            notes: $notes,
            ragLimit: $definition->ragLimit(),
            ragMinScore: $definition->ragMinScore(),
        );
    }

    /**
     * @param list<string> $notes
     *
     * @return array<string, int|null>
     */
    private function resolveModels(AgentDefinition $definition, int $userId, array &$notes): array
    {
        $capabilityToSetting = [
            'chat' => 'CHAT',
            'vision' => 'CHAT',
            'vectorize' => 'VECTORIZE',
        ];

        $resolved = [];
        foreach ($definition->models() as $capability => $key) {
            $setting = $capabilityToSetting[$capability] ?? strtoupper($capability);
            if (null === $key || '' === $key) {
                $resolved[$capability] = $this->modelConfigService->getDefaultModel($setting, $userId);
                continue;
            }

            $bid = ModelCatalog::findBidByKey($key);
            if (null === $bid || !$this->isUsableModel($bid)) {
                $notes[] = 'model_fallback:'.$capability;
                $resolved[$capability] = $this->modelConfigService->getDefaultModel($setting, $userId);
                continue;
            }

            $resolved[$capability] = $bid;
        }

        return $resolved;
    }

    private function isUsableModel(int $bid): bool
    {
        $model = $this->models->find($bid);

        return $model instanceof Model && 1 === $model->getActive();
    }
}
