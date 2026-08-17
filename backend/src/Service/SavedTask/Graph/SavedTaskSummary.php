<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Entity\SavedTask;

final class SavedTaskSummary
{
    /**
     * One-sentence description for the task card (English source; UI translates).
     *
     * @return array{key: string, params: array<string, string>}
     */
    public function describe(SavedTask $task): array
    {
        $when = match ($task->getTriggerType()) {
            SavedTask::TRIGGER_SCHEDULE => $this->schedulePhrase($task->getTriggerConfig() ?? []),
            SavedTask::TRIGGER_INBOUND_EMAIL => 'when new mail arrives',
            SavedTask::TRIGGER_CHAT => 'when a matching chat arrives',
            default => 'when you run it',
        };

        $reads = 'this instruction';
        $saves = 'a calendar file';
        $graph = $task->getGraph();
        if (is_array($graph) && is_array($graph['nodes'] ?? null)) {
            $caps = [];
            foreach ($graph['nodes'] as $node) {
                if (is_array($node) && is_string($node['capability'] ?? null)) {
                    $caps[] = $node['capability'];
                }
            }
            if (in_array('email_search', $caps, true)) {
                $reads = 'the connected mailbox';
            }
            if (in_array('email_me', $caps, true)) {
                $saves = 'an email';
            } elseif (in_array('save_to_folder', $caps, true)) {
                $saves = 'the connected folder';
            } elseif (in_array('calendar_event', $caps, true)) {
                $saves = 'a calendar file';
            } elseif (in_array('compose_reply', $caps, true)) {
                $saves = 'a chat reply';
            }
        }

        return [
            'key' => 'config.savedTasks.summary.template',
            'params' => [
                'when' => $when,
                'reads' => $reads,
                'saves' => $saves,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function schedulePhrase(array $config): string
    {
        $kind = $config['kind'] ?? '';
        $tz = is_string($config['tz'] ?? null) ? $config['tz'] : 'UTC';
        $at = is_string($config['at'] ?? null) ? $config['at'] : '';

        return match ($kind) {
            'interval' => sprintf('every %d minutes', (int) ($config['every_minutes'] ?? 60)),
            'daily' => sprintf('every day at %s (%s)', $at, $tz),
            'weekly' => sprintf('every weekday at %s (%s)', $at, $tz),
            default => 'on a schedule',
        };
    }
}
