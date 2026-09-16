<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Entity\Widget;
use App\Repository\WidgetRepository;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\SavedTaskService;

/**
 * Resolved trigger rows for GET /api/v1/agents/{id}/triggers.
 *
 * @phpstan-type TriggerRow array{
 *   id: string,
 *   kind: string,
 *   group: string,
 *   what: string,
 *   when: string,
 *   does: string,
 *   runsAs: string,
 *   status: string,
 *   lastRun: array{at: int|null, status: string}|null,
 *   savedTaskId: int|null,
 *   target: array{kind: string, name: string, id: string},
 *   implicit?: bool
 * }
 */
final readonly class AgentTriggerResolver
{
    public function __construct(
        private AgentDefinitionValidator $validator,
        private SavedTaskService $savedTasks,
        private SavedTaskConfig $savedTaskConfig,
        private WidgetRepository $widgets,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, availableKinds: list<string>, savedTasksEnabled: bool}
     */
    public function resolve(Agent $agent, User $actor): array
    {
        $definition = $this->validator->validate($agent->getDraft());
        $triggers = $definition->toArray()['triggers'] ?? ['events' => [], 'schedules' => []];
        $events = is_array($triggers['events'] ?? null) ? $triggers['events'] : [];
        $schedules = is_array($triggers['schedules'] ?? null) ? $triggers['schedules'] : [];
        $savedOn = $this->savedTaskConfig->isEnabled((int) $actor->getId());

        $rows = [$this->chatRow()];
        $seenWidgets = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $kind = (string) ($event['kind'] ?? '');
            if ('webhook' === $kind) {
                continue;
            }
            if ('mail' === $kind && isset($event['rule']) && !$savedOn) {
                continue;
            }
            $row = $this->eventRow($agent, $event);
            $rows[] = $row;
            if ('widget' === $kind) {
                $seenWidgets[(string) ($event['widget'] ?? '')] = true;
            }
        }
        if ($savedOn) {
            foreach ($schedules as $schedule) {
                if (is_array($schedule)) {
                    $rows[] = $this->scheduleRow($agent, $schedule);
                }
            }
        }

        foreach ($this->widgets->findBy(['agentId' => $agent->getId(), 'ownerId' => $agent->getOwnerId()]) as $widget) {
            $ref = $widget->getOwnerId().':'.$widget->getWidgetId();
            if (isset($seenWidgets[$ref])) {
                continue;
            }
            $rows[] = $this->channelWidgetRow($widget);
        }

        $kinds = ['mail', 'widget', 'whatsapp', 'api', 'mcp', 'desktop'];
        if (!$savedOn) {
            $kinds = ['widget', 'whatsapp', 'api', 'mcp', 'desktop'];
        }

        return [
            'rows' => $rows,
            'availableKinds' => $kinds,
            'savedTasksEnabled' => $savedOn,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chatRow(): array
    {
        return [
            'id' => 'chat',
            'kind' => 'chat',
            'group' => 'always',
            'what' => 'chat',
            'when' => 'always',
            'does' => 'answers',
            'runsAs' => 'person_talking',
            'status' => 'on',
            'lastRun' => null,
            'savedTaskId' => null,
            'target' => ['kind' => 'chat', 'name' => '', 'id' => ''],
            'implicit' => true,
        ];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function eventRow(Agent $agent, array $event): array
    {
        $kind = (string) ($event['kind'] ?? '');
        $id = (string) ($event['id'] ?? '');
        $task = $this->savedTasks->findAgentTrigger($agent->getOwnerId(), $agent->getPromptId(), $agent->getSlug().':'.$id);
        $status = false === ($event['enabled'] ?? true) ? 'off' : 'on';
        if ($task instanceof SavedTask && $task->isAutoPaused()) {
            $status = 'paused_auto';
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'group' => 'event',
            'what' => $kind,
            'when' => $this->eventWhen($event),
            'does' => isset($event['instruction']) && is_string($event['instruction']) && '' !== trim($event['instruction'])
                ? 'instruction'
                : 'answers',
            'runsAs' => in_array($kind, ['mail', 'webhook'], true) ? 'owner_unattended' : 'person_talking',
            'status' => $status,
            'lastRun' => $this->lastRun($task),
            'savedTaskId' => $task?->getId(),
            'target' => $this->eventTarget($event),
        ];
    }

    /**
     * @param array<string, mixed> $schedule
     *
     * @return array<string, mixed>
     */
    private function scheduleRow(Agent $agent, array $schedule): array
    {
        $id = (string) ($schedule['id'] ?? '');
        $task = $this->savedTasks->findAgentTrigger($agent->getOwnerId(), $agent->getPromptId(), $agent->getSlug().':'.$id);
        $status = false === ($schedule['enabled'] ?? true) ? 'off' : 'on';
        if ($task instanceof SavedTask && $task->isAutoPaused()) {
            $status = 'paused_auto';
        }

        return [
            'id' => $id,
            'kind' => 'schedule',
            'group' => 'schedule',
            'what' => 'schedule',
            'when' => (string) ($schedule['cron'] ?? ''),
            'does' => 'instruction',
            'runsAs' => 'owner_unattended',
            'status' => $status,
            'lastRun' => $this->lastRun($task),
            'savedTaskId' => $task?->getId(),
            'target' => [
                'kind' => 'schedule',
                'name' => (string) ($schedule['name'] ?? ''),
                'id' => $id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelWidgetRow(Widget $widget): array
    {
        return [
            'id' => 'widget-'.$widget->getWidgetId(),
            'kind' => 'widget',
            'group' => 'event',
            'what' => 'widget',
            'when' => $widget->getName(),
            'does' => 'answers',
            'runsAs' => 'visitor',
            'status' => $widget->isActive() ? 'on' : 'off',
            'lastRun' => null,
            'savedTaskId' => null,
            'target' => [
                'kind' => 'widget',
                'name' => $widget->getName(),
                'id' => $widget->getOwnerId().':'.$widget->getWidgetId(),
            ],
            'adopted' => true,
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventWhen(array $event): string
    {
        return match ($event['kind'] ?? '') {
            'mail' => (string) ($event['mailbox'] ?? ''),
            'widget' => (string) ($event['widget'] ?? ''),
            'whatsapp' => (string) ($event['number'] ?? ''),
            'api' => 'api',
            'mcp' => 'mcp',
            'desktop' => 'desktop',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{kind: string, name: string, id: string}
     */
    private function eventTarget(array $event): array
    {
        $kind = (string) ($event['kind'] ?? '');

        return [
            'kind' => $kind,
            'name' => $this->eventWhen($event),
            'id' => $this->eventWhen($event),
        ];
    }

    /**
     * @return array{at: int|null, status: string}|null
     */
    private function lastRun(?SavedTask $task): ?array
    {
        if (!$task instanceof SavedTask || null === $task->getId()) {
            return null;
        }
        $last = $task->getLastRunAt();
        if (null === $last) {
            return null;
        }

        return [
            'at' => $last->getTimestamp(),
            'status' => $task->getConsecutiveFailures() > 0 ? 'failed' : 'ok',
        ];
    }
}
