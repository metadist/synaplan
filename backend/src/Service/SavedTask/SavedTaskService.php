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
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\Schedule\ScheduleParser;

final readonly class SavedTaskService
{
    public function __construct(
        private SavedTaskRepository $tasks,
        private SavedTaskRunRepository $runs,
        private PromptRepository $prompts,
        private SavedTaskGraphValidator $graphValidator,
        private ScheduleParser $scheduleParser,
        private AccessGate $accessGate,
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
        $copy->setGraph($source->getGraph());
        $copy->setTrigger(SavedTask::TRIGGER_MANUAL, null);
        $copy->setAllowUnattended(false);
        $this->tasks->save($copy);

        return $copy;
    }

    public function findForPrompt(int $promptId, int $ownerId): ?SavedTask
    {
        return $this->tasks->findByPromptAndOwner($promptId, $ownerId);
    }

    public function create(int $ownerId, int $promptId, string $name): SavedTask
    {
        $this->assertUsablePrompt($promptId, $ownerId);
        $existing = $this->tasks->findByPromptAndOwner($promptId, $ownerId);
        if (null !== $existing) {
            return $existing;
        }

        $task = new SavedTask($ownerId, $promptId, $name);
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
            $task->setTrigger($data['triggerType'], $config);
        } elseif (isset($data['triggerConfig']) && is_array($data['triggerConfig'])) {
            $task->setTrigger($task->getTriggerType(), $data['triggerConfig']);
        }

        if (array_key_exists('graph', $data)) {
            $graph = $data['graph'];
            if (null !== $graph && !is_array($graph)) {
                throw new \InvalidArgumentException('graph must be an object or null');
            }
            /** @var array<string, mixed>|null $graph */
            if (null !== $graph) {
                $errors = $this->graphValidator->validate($graph, $task->getTriggerType(), $task->getTriggerConfig());
                if ([] !== $errors) {
                    throw new \InvalidArgumentException(implode('; ', $errors));
                }
            }
            $task->setGraph($graph);
        }

        if (SavedTask::TRIGGER_SCHEDULE === $task->getTriggerType()) {
            $this->assertScheduleAllowed($task);
            $task->setNextRunAt($this->scheduleParser->nextRunAt($task->getTriggerConfig(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        }

        $this->tasks->save($task);

        return $task;
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
        if (SavedTask::TRIGGER_SCHEDULE === $task->getTriggerType()) {
            $this->assertScheduleAllowed($task);
            $task->setNextRunAt($this->scheduleParser->nextRunAt($task->getTriggerConfig(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        }
        $this->tasks->save($task);

        return $task;
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

    private function assertScheduleAllowed(SavedTask $task): void
    {
        $graph = $task->getGraph() ?? [];
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $mutating = false;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $capability = (string) ($node['capability'] ?? '');
            if (in_array($capability, ['email_me', 'save_to_folder', 'outbound_webhook', 'mcp_action'], true)) {
                $mutating = true;
                break;
            }
        }
        if ($mutating && !$task->allowsUnattended()) {
            throw new \InvalidArgumentException('This schedule would send or save files on its own. Confirm “runs on its own” first.');
        }
    }
}
