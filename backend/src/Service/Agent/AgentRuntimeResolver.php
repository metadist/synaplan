<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\AgentVersion;
use App\Entity\Model;
use App\Entity\Prompt;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentArchivedException;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Agent\Exception\AgentNotPublishedException;
use App\Service\Iam\Permission;
use App\Service\ModelConfigService;
use App\Service\Runtime\RuntimeProfile;

/**
 * Turns an assistant id into a {@see RuntimeProfile} the chat path can consume.
 *
 * Owner + draft=true uses BDRAFT. Everyone else (and the owner without draft)
 * needs IAM `use` and runs the latest published version. Archived assistants
 * only continue an existing chat that already carries AGENTID.
 */
final readonly class AgentRuntimeResolver
{
    public function __construct(
        private AgentRepository $agents,
        private AgentVersionRepository $versions,
        private PromptRepository $prompts,
        private ModelRepository $models,
        private ModelConfigService $modelConfigService,
        private AgentDefinitionValidator $validator,
        private AgentAccess $access,
        private MessageMetaRepository $messageMeta,
    ) {
    }

    public function resolve(int $agentId, User $user, bool $draft = false, ?int $chatId = null): RuntimeProfile
    {
        $agent = $this->agents->find($agentId);
        if (!$agent instanceof Agent) {
            throw AgentNotAccessibleException::forId($agentId);
        }

        $isOwner = $agent->getOwnerId() === (int) $user->getId();

        if ($draft) {
            if (!$isOwner) {
                throw AgentNotAccessibleException::forId($agentId);
            }

            return $this->fromDefinition($agent, $user, $this->validator->validate($agent->getDraft()), '', null);
        }

        if (!$this->access->can($user, $agent, Permission::Use)) {
            throw AgentNotAccessibleException::forId($agentId);
        }

        if ($agent->isArchived()) {
            if (null === $chatId || $chatId < 1 || !$this->messageMeta->chatHasAgent($chatId, $agentId)) {
                throw AgentArchivedException::forId($agentId);
            }
        }

        if (!$agent->hasPublishedVersion()) {
            if ($isOwner) {
                return $this->fromDefinition($agent, $user, $this->validator->validate($agent->getDraft()), '', null);
            }
            throw AgentNotPublishedException::forId($agentId);
        }

        $version = $this->versions->find($agent->getPublishedVersionId());
        if (!$version instanceof AgentVersion) {
            throw AgentNotPublishedException::forId($agentId);
        }

        return $this->fromDefinition(
            $agent,
            $user,
            $this->validator->validate($version->getDefinition()),
            $version->getPromptText(),
            $version->getId(),
        );
    }

    private function fromDefinition(
        Agent $agent,
        User $user,
        AgentDefinition $definition,
        string $systemPrompt,
        ?int $agentVersionId,
    ): RuntimeProfile {
        $prompt = $this->prompts->find($agent->getPromptId());
        $topic = $prompt instanceof Prompt ? $prompt->getTopic() : 'agent:'.$agent->getSlug();
        if ('' === trim($systemPrompt) && $prompt instanceof Prompt) {
            $systemPrompt = $prompt->getPrompt();
        }
        if ('' === trim($systemPrompt)) {
            $systemPrompt = AgentService::DEFAULT_INSTRUCTION;
        }

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
            agentVersionId: $agentVersionId,
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
