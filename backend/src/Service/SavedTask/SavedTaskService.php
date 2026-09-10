<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Exception\AssistantNotSharedException;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AssistantKind;
use App\Service\Iam\ResourceKind\SavedTaskKind;
use App\Service\Multitask\Plan\Capability;
use App\Service\SavedTask\Graph\SavedTaskGraphCapture;
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\Schedule\ScheduleParser;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;

final readonly class SavedTaskService
{
    public function __construct(
        private SavedTaskRepository $tasks,
        private SavedTaskRunRepository $runs,
        private PromptRepository $prompts,
        private SavedTaskGraphValidator $graphValidator,
        private ScheduleParser $scheduleParser,
        private AccessGate $accessGate,
        private SavedTaskGraphCapture $graphCapture,
        private ?ToolsConfig $toolsConfig = null,
        private ?WorkflowsConfig $workflowsConfig = null,
        private ?ToolRegistry $toolRegistry = null,
    ) {
    }

    /**
     * @return list<SavedTask>
     */
    public function listForOwner(int $ownerId): array
    {
        return $this->tasks->findByOwner($ownerId);
    }

    public function getOwned(int $id, int $ownerId): ?SavedTask
    {
        return $this->tasks->findByIdAndOwner($id, $ownerId);
    }

    public function getForRead(int $id, User $user): ?SavedTask
    {
        $task = $this->tasks->find($id);
        if (!$task instanceof SavedTask) {
            return null;
        }
        if ($task->getOwnerId() === (int) $user->getId()) {
            return $task;
        }
        if ($this->accessGate->decide($user, SavedTaskKind::KEY, (string) $id, Permission::Read)) {
            return $task;
        }

        return null;
    }

    public function copyForOwner(SavedTask $source, User $user): SavedTask
    {
        if (!$this->accessGate->decide($user, SavedTaskKind::KEY, (string) $source->getId(), Permission::Use)) {
            throw new SavedTaskNotFoundException();
        }

        $prompt = $this->prompts->find($source->getPromptId());
        $userId = (int) $user->getId();
        $assistantOk = $prompt instanceof Prompt
            && $prompt->isEnabled()
            && (
                0 === $prompt->getOwnerId()
                || $prompt->getOwnerId() === $userId
                || $this->accessGate->decide(
                    $user,
                    AssistantKind::KEY,
                    (string) $prompt->getId(),
                    Permission::Use,
                )
            );
        if (!$assistantOk) {
            throw new AssistantNotSharedException();
        }

        $copy = new SavedTask($userId, $source->getPromptId(), $source->getName());
        $copy->setTrigger(SavedTask::TRIGGER_MANUAL, null);
        // The recipient gets the steps, never the source owner's shared secrets.
        $sourceGraph = $source->getGraph();
        $copy->setGraph(null !== $sourceGraph ? $this->withoutOutboundSecrets($this->withTriggerType($sourceGraph, SavedTask::TRIGGER_MANUAL)) : null);
        $copy->setAllowUnattended(false);
        $this->tasks->save($copy);

        return $copy;
    }

    public function findForPrompt(int $promptId, int $ownerId): ?SavedTask
    {
        return $this->tasks->findByPromptAndOwner($promptId, $ownerId);
    }

    /**
     * One Saved Task per assistant trigger id. Unlike {@see create()} this
     * does not collapse to a single row per prompt — an assistant may own
     * several schedules and mail rules.
     *
     * @param array<string, mixed> $data
     */
    public function upsertAgentTrigger(
        int $ownerId,
        int $promptId,
        string $name,
        string $agentTrigger,
        array $data,
    ): SavedTask {
        $this->assertUsablePrompt($promptId, $ownerId);
        $existing = $this->findAgentTrigger($ownerId, $promptId, $agentTrigger);
        if (!$existing instanceof SavedTask) {
            $existing = new SavedTask($ownerId, $promptId, $name);
            $this->tasks->save($existing);
        }

        $config = is_array($data['triggerConfig'] ?? null) ? $data['triggerConfig'] : [];
        $config['agentTrigger'] = $agentTrigger;
        $data['triggerConfig'] = $config;
        $data['name'] = $name;

        return $this->update($existing, $data);
    }

    public function findAgentTrigger(int $ownerId, int $promptId, string $agentTrigger): ?SavedTask
    {
        foreach ($this->tasks->findAllByPromptAndOwner($promptId, $ownerId) as $task) {
            if (($task->getTriggerConfig()['agentTrigger'] ?? null) === $agentTrigger) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @return list<SavedTask>
     */
    public function listAgentTriggers(int $ownerId, int $promptId): array
    {
        $out = [];
        foreach ($this->tasks->findAllByPromptAndOwner($promptId, $ownerId) as $task) {
            $key = $task->getTriggerConfig()['agentTrigger'] ?? null;
            if (is_string($key) && '' !== $key) {
                $out[] = $task;
            }
        }

        return $out;
    }

    public function create(int $ownerId, int $promptId, string $name, ?int $sourceMessageId = null): SavedTask
    {
        $this->assertUsablePrompt($promptId, $ownerId);
        $existing = $this->tasks->findByPromptAndOwner($promptId, $ownerId);
        if (null !== $existing) {
            return $existing;
        }

        $task = new SavedTask($ownerId, $promptId, $name);
        if (null !== $sourceMessageId && $sourceMessageId > 0) {
            $graph = $this->graphCapture->fromMessage($sourceMessageId, $ownerId, $task->getTriggerType());
            if (null !== $graph && [] === $this->graphValidator->validate($graph, $task->getTriggerType(), $task->getTriggerConfig(), $ownerId)) {
                $task->setGraph($graph);
            }
        }
        $this->tasks->save($task);

        return $task;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(SavedTask $task, array $data): SavedTask
    {
        if (isset($data['name']) && is_string($data['name']) && '' !== trim($data['name'])) {
            $task->setName(trim($data['name']));
        }
        if (array_key_exists('enabled', $data)) {
            $task->setEnabled((bool) $data['enabled']);
        }
        if (array_key_exists('allowUnattended', $data)) {
            $task->setAllowUnattended((bool) $data['allowUnattended']);
        }
        if (isset($data['triggerType']) && is_string($data['triggerType'])) {
            $config = is_array($data['triggerConfig'] ?? null) ? $data['triggerConfig'] : $task->getTriggerConfig();
            $this->applyTrigger($task, $data['triggerType'], $config, $data);
        } elseif (isset($data['triggerConfig']) && is_array($data['triggerConfig'])) {
            $this->applyTrigger($task, $task->getTriggerType(), $data['triggerConfig'], $data);
        } elseif (true === ($data['regenerateWebhookToken'] ?? false)) {
            $this->applyTrigger($task, $task->getTriggerType(), $task->getTriggerConfig(), $data);
        }

        // The graph carries the trigger it was authored for and the factory
        // rejects a mismatch — silently, falling back to the planner. Switching
        // "Run now" → "Every day" must keep the pinned steps, so follow along.
        $graph = $task->getGraph();
        if (!array_key_exists('graph', $data) && null !== $graph
            && ($graph['trigger']['type'] ?? null) !== $task->getTriggerType()) {
            $task->setGraph($this->withTriggerType($graph, $task->getTriggerType()));
        }

        if (array_key_exists('graph', $data)) {
            $graph = $data['graph'];
            if (null !== $graph && !is_array($graph)) {
                throw new \InvalidArgumentException('graph must be an object or null');
            }
            /** @var array<string, mixed>|null $graph */
            if (null !== $graph) {
                $graph = $this->keepOutboundSecrets($graph, $task->getGraph());
                $errors = $this->graphValidator->validate($graph, $task->getTriggerType(), $task->getTriggerConfig(), $task->getOwnerId());
                if ([] !== $errors) {
                    throw new \InvalidArgumentException(implode('; ', $errors));
                }
                $this->assertToolsConnected($graph, $task->getOwnerId());
            }
            $task->setGraph($graph);
        }

        $this->assertUnattendedAllowed($task);
        if (SavedTask::TRIGGER_SCHEDULE === $task->getTriggerType()) {
            $task->setNextRunAt($this->scheduleParser->nextRunAt($task->getTriggerConfig(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        }

        $this->tasks->save($task);

        return $task;
    }

    /**
     * Like {@see update()}, but also returns a freshly minted HMAC secret exactly
     * once, on the response that created it. It is never serialized afterwards.
     *
     * @param array<string, mixed> $data
     *
     * @return array{task: SavedTask, webhookSecret: string|null}
     */
    public function updateAndRevealWebhookSecret(SavedTask $task, array $data): array
    {
        $before = $task->getTriggerConfig()['hmacSecret'] ?? null;
        $task = $this->update($task, $data);
        $after = $task->getTriggerConfig()['hmacSecret'] ?? null;
        $revealed = is_string($after) && '' !== $after && $after !== $before ? $after : null;

        return ['task' => $task, 'webhookSecret' => $revealed];
    }

    public function delete(SavedTask $task): void
    {
        $id = $task->getId();
        if (null !== $id) {
            $this->runs->deleteForTask($id);
        }
        $this->tasks->remove($task);
    }

    public function resume(SavedTask $task): SavedTask
    {
        $task->resume();
        $this->assertUnattendedAllowed($task);
        if (SavedTask::TRIGGER_SCHEDULE === $task->getTriggerType()) {
            $task->setNextRunAt($this->scheduleParser->nextRunAt($task->getTriggerConfig(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        }
        $this->tasks->save($task);

        return $task;
    }

    /**
     * @param array<string, mixed> $graph
     *
     * @return array<string, mixed>
     */
    private function withTriggerType(array $graph, string $triggerType): array
    {
        $graph['trigger'] = ['type' => $triggerType];

        return $graph;
    }

    /**
     * @param array<string, mixed>|null $config
     * @param array<string, mixed>      $data
     */
    private function applyTrigger(SavedTask $task, string $type, ?array $config, array $data): void
    {
        // The client never supplies the token or the secret; both are minted here.
        if (is_array($config)) {
            unset($config['token'], $config['hmacSecret'], $config['hmacConfigured']);
        }
        if (SavedTask::TRIGGER_WEBHOOK === $type) {
            if (null === $this->workflowsConfig || !$this->workflowsConfig->isBuilderEnabled($task->getOwnerId())) {
                throw new \InvalidArgumentException('Letting another system start this is turned off');
            }
            $config = is_array($config) ? $config : [];
            $existing = $task->getTriggerConfig() ?? [];
            $token = is_string($existing['token'] ?? null) ? $existing['token'] : '';
            if (true === ($data['regenerateWebhookToken'] ?? false) || '' === $token) {
                $token = $this->randomToken();
            }
            $config['token'] = $token;
            if (true === ($data['hmacEnabled'] ?? false)) {
                $secret = is_string($existing['hmacSecret'] ?? null) ? $existing['hmacSecret'] : '';
                $config['hmacSecret'] = '' !== $secret ? $secret : $this->randomToken();
            } elseif (!array_key_exists('hmacEnabled', $data) && is_string($existing['hmacSecret'] ?? null) && '' !== $existing['hmacSecret']) {
                $config['hmacSecret'] = $existing['hmacSecret'];
            }
        }
        $task->setTrigger($type, $config);
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function assertUsablePrompt(int $promptId, int $ownerId): void
    {
        $prompt = $this->prompts->find($promptId);
        if (!$prompt instanceof Prompt) {
            throw new \InvalidArgumentException('Task Prompt was not found');
        }
        if ($prompt->getOwnerId() !== $ownerId && 0 !== $prompt->getOwnerId()) {
            throw new \InvalidArgumentException('Task Prompt was not found');
        }
        if (!$prompt->isEnabled()) {
            throw new \InvalidArgumentException('This Task Prompt is turned off');
        }
    }

    /**
     * A schedule or an inbound webhook runs with nobody watching. Steps that
     * send or save need the owner's explicit "runs on its own" — or approvals,
     * which pause the run instead.
     */
    private function assertUnattendedAllowed(SavedTask $task): void
    {
        $unattended = in_array($task->getTriggerType(), [SavedTask::TRIGGER_SCHEDULE, SavedTask::TRIGGER_WEBHOOK], true);
        if (!$unattended) {
            return;
        }
        $graph = $task->getGraph() ?? [];
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $mutating = false;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $capability = (string) ($node['capability'] ?? '');
            if (in_array($capability, ['email_me', 'save_to_folder', 'outbound_webhook', 'mcp_action', 'tool_call'], true)) {
                $mutating = true;
                break;
            }
        }
        if ($mutating && !$task->allowsUnattended()) {
            if (null !== $this->toolsConfig && $this->toolsConfig->isApprovalsEnabled($task->getOwnerId())) {
                return;
            }
            $who = SavedTask::TRIGGER_WEBHOOK === $task->getTriggerType() ? 'Another system starting this' : 'This schedule';

            throw new \InvalidArgumentException($who.' would send or save files on its own. Confirm “runs on its own” first.');
        }
    }

    /**
     * Every "Use a tool" step must point at a tool the owner has connected and
     * that can run without a chat. Catching this on save beats a failed run.
     *
     * @param array<string, mixed> $graph
     */
    private function assertToolsConnected(array $graph, int $ownerId): void
    {
        if (null === $this->toolRegistry) {
            return;
        }
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        foreach (array_values($nodes) as $index => $node) {
            if (!is_array($node) || Capability::ToolCall->value !== ($node['capability'] ?? null)) {
                continue;
            }
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            $tool = is_string($params['tool'] ?? null) ? trim($params['tool']) : '';
            $descriptor = '' !== $tool ? $this->toolRegistry->get($ownerId, $tool) : null;
            if (null === $descriptor) {
                throw new \InvalidArgumentException(sprintf('Step %d uses a tool that is not connected', $index + 1));
            }
            if (!in_array($descriptor->source, [ToolSource::Custom, ToolSource::Mcp], true)) {
                throw new \InvalidArgumentException(sprintf('Step %d uses a tool that cannot run as a step', $index + 1));
            }
        }
    }

    /**
     * The serializer never returns an outbound step's shared secret; the editor
     * sends the step back without it. Keep the stored secret for the same step
     * unless the owner typed a new one or cleared it.
     *
     * @param array<string, mixed>      $graph
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function keepOutboundSecrets(array $graph, ?array $existing): array
    {
        $stored = [];
        foreach (is_array($existing['nodes'] ?? null) ? $existing['nodes'] : [] as $node) {
            if (!is_array($node) || Capability::OutboundWebhook->value !== ($node['capability'] ?? null)) {
                continue;
            }
            $secret = $node['params']['secret'] ?? null;
            if (is_string($node['id'] ?? null) && is_string($secret) && '' !== $secret) {
                $stored[$node['id']] = $secret;
            }
        }
        if (!is_array($graph['nodes'] ?? null)) {
            return $graph;
        }
        foreach ($graph['nodes'] as $i => $node) {
            if (!is_array($node) || Capability::OutboundWebhook->value !== ($node['capability'] ?? null)) {
                continue;
            }
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            $keep = true === ($params['secretConfigured'] ?? false) && !array_key_exists('secret', $params);
            unset($params['secretConfigured']);
            $id = $node['id'] ?? null;
            if ($keep && is_string($id) && isset($stored[$id])) {
                $params['secret'] = $stored[$id];
            }
            $graph['nodes'][$i]['params'] = $params;
        }

        return $graph;
    }

    /**
     * @param array<string, mixed> $graph
     *
     * @return array<string, mixed>
     */
    private function withoutOutboundSecrets(array $graph): array
    {
        if (!is_array($graph['nodes'] ?? null)) {
            return $graph;
        }
        foreach ($graph['nodes'] as $i => $node) {
            if (is_array($node) && Capability::OutboundWebhook->value === ($node['capability'] ?? null) && is_array($node['params'] ?? null)) {
                unset($graph['nodes'][$i]['params']['secret']);
            }
        }

        return $graph;
    }
}
