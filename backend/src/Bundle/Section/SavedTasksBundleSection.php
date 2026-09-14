<?php

declare(strict_types=1);

namespace App\Bundle\Section;

use App\Bundle\BundleScope;
use App\Bundle\BundleSectionInterface;
use App\Bundle\ChecklistItem;
use App\Bundle\ImportOptions;
use App\Bundle\SectionPreview;
use App\Bundle\SectionResult;
use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\UserRepository;
use App\Service\SavedTask\Graph\SavedTaskGraphPortability;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\Schedule\ScheduleParser;
use App\Service\Tool\ToolRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.bundle.section')]
final readonly class SavedTasksBundleSection implements BundleSectionInterface
{
    public function __construct(
        private SavedTaskRepository $tasks,
        private SavedTaskConfig $savedTaskConfig,
        private SavedTaskGraphPortability $portability,
        private PromptRepository $prompts,
        private UserRepository $users,
        private ?ToolRegistry $toolRegistry = null,
        private ?ScheduleParser $schedules = null,
    ) {
    }

    public function kind(): string
    {
        return 'saved_tasks';
    }

    public function version(): int
    {
        return 1;
    }

    public function isAvailable(?int $userId, BundleScope $scope): bool
    {
        return $this->savedTaskConfig->isEnabled($userId);
    }

    public function dependsOn(): array
    {
        return ['prompts', 'mcp_servers'];
    }

    public function export(int $userId, BundleScope $scope, array $include = []): array
    {
        $items = [];
        foreach ($this->tasks->findByOwner($userId) as $task) {
            $prompt = $this->prompts->find($task->getPromptId());
            $items[] = [
                'key' => $this->itemKey($task),
                'name' => $task->getName(),
                'prompt' => $prompt instanceof Prompt ? $prompt->getTopic() : '',
                'triggerType' => $task->getTriggerType(),
                'triggerConfig' => $this->portability->exportTriggerConfig($task->getTriggerConfig()),
                'graph' => $this->portability->exportGraph($task->getGraph(), $userId),
                'settings' => ['allowUnattended' => false],
            ];
        }

        return $items;
    }

    public function preview(array $items, int $userId): SectionPreview
    {
        $rows = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? $item['name'] ?? '');
            foreach ($this->portability->unknownItemKeys($item) as $unknown) {
                $rows[] = new ChecklistItem('unknownKey', $key, $unknown);
            }
            $topic = is_string($item['prompt'] ?? null) ? $item['prompt'] : '';
            if ('' !== $topic && !$this->portability->usablePromptByTopic($topic, $userId) instanceof Prompt) {
                $rows[] = new ChecklistItem('needsAssistant', $key, $topic);
            }
            $trigger = is_string($item['triggerType'] ?? null) ? $item['triggerType'] : SavedTask::TRIGGER_MANUAL;
            $importedTrigger = $this->portability->importTriggerConfig(
                is_array($item['triggerConfig'] ?? null) ? $item['triggerConfig'] : null,
                $trigger,
                $userId,
            );
            foreach ($importedTrigger['checklist'] as $row) {
                $rows[] = new ChecklistItem($row['code'], $key, $row['detail']);
            }
            $imported = $this->portability->importGraph(is_array($item['graph'] ?? null) ? $item['graph'] : null, $userId);
            foreach ($imported['checklist'] as $row) {
                $rows[] = new ChecklistItem($row['code'], $key, $row['detail']);
            }
            if (null !== $this->toolRegistry) {
                foreach ($this->portability->toolNames($imported['graph'] ?? []) as $toolName) {
                    if (null === $this->toolRegistry->get($userId, $toolName)) {
                        $rows[] = new ChecklistItem('needsTool', $key, $toolName);
                    }
                }
            }
            if (in_array($trigger, [SavedTask::TRIGGER_SCHEDULE, SavedTask::TRIGGER_WEBHOOK], true)) {
                $rows[] = new ChecklistItem('schedulesOff', $key, $trigger);
            }
        }

        return new SectionPreview($this->kind(), $rows, count($items));
    }

    public function apply(array $items, int $userId, ImportOptions $options): SectionResult
    {
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return new SectionResult($this->kind(), failed: [['key' => '*', 'reason' => 'User not found']]);
        }
        $created = [];
        $skipped = [];
        $failed = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? $item['name'] ?? '');
            $unknown = $this->portability->unknownItemKeys($item);
            if ([] !== $unknown) {
                $failed[] = ['key' => $key, 'reason' => 'Unknown fields: '.implode(', ', $unknown)];
                continue;
            }
            try {
                $this->importOne($item, $userId);
                $created[] = $key;
            } catch (\InvalidArgumentException $e) {
                if (ImportOptions::CONFLICT_SKIP === $options->conflict && str_contains($e->getMessage(), 'already exists')) {
                    $skipped[] = $key;
                    continue;
                }
                $failed[] = ['key' => $key, 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'reason' => $e->getMessage()];
            }
        }

        return new SectionResult($this->kind(), $created, $skipped, $failed);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function importOne(array $item, int $userId): void
    {
        $name = is_string($item['name'] ?? null) && '' !== trim($item['name']) ? trim($item['name']) : 'Imported task';
        $topic = is_string($item['prompt'] ?? null) ? $item['prompt'] : '';
        $prompt = '' !== $topic ? $this->portability->usablePromptByTopic($topic, $userId) : null;
        if (!$prompt instanceof Prompt) {
            $prompt = $this->prompts->findFirstUsableForUser($userId);
        }
        if (!$prompt instanceof Prompt) {
            throw new \InvalidArgumentException('Needs an assistant');
        }

        $triggerType = is_string($item['triggerType'] ?? null) ? $item['triggerType'] : SavedTask::TRIGGER_MANUAL;
        if (!in_array($triggerType, SavedTask::TRIGGER_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown trigger');
        }
        $importedTrigger = $this->portability->importTriggerConfig(
            is_array($item['triggerConfig'] ?? null) ? $item['triggerConfig'] : null,
            $triggerType,
            $userId,
        );
        $config = $importedTrigger['config'];
        if (SavedTask::TRIGGER_WEBHOOK === $triggerType) {
            $config = is_array($config) ? $config : [];
            $config['token'] = $this->randomToken();
        }

        $imported = $this->portability->importGraph(is_array($item['graph'] ?? null) ? $item['graph'] : null, $userId);
        $graph = $imported['graph'];
        if (is_array($graph)) {
            $graph['trigger'] = ['type' => $triggerType];
        }

        $task = new SavedTask($userId, (int) $prompt->getId(), $name);
        $task->setEnabled(false);
        $task->setAllowUnattended(false);
        $task->setTrigger($triggerType, $config);
        $task->setGraph($graph);
        $this->refreshSchedule($task);
        $this->tasks->save($task);
    }

    private function itemKey(SavedTask $task): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $task->getName()));
        $slug = trim($slug, '-');

        return '' !== $slug ? $slug : 'task-'.(string) $task->getId();
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function refreshSchedule(SavedTask $task): void
    {
        if (SavedTask::TRIGGER_SCHEDULE !== $task->getTriggerType() || null === $this->schedules) {
            return;
        }
        try {
            $task->setNextRunAt($this->schedules->nextRunAt(
                $task->getTriggerConfig(),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ));
        } catch (\InvalidArgumentException) {
            $task->setNextRunAt(null);
        }
    }
}
