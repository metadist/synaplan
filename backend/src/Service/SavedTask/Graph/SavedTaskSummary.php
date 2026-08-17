<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Entity\SavedTask;

/**
 * Machine-readable summary for the Saved Task card.
 *
 * Emits language-neutral part CODES (never English prose) so the frontend can
 * translate every fragment; mixing server-side English into a localized
 * template produced German/English hybrid sentences on the card.
 *
 * Contract with the frontend (SavedTaskCard.vue):
 *   key    — which sentence template to use:
 *            config.savedTasks.summary.simple   (instruction-only task, no step graph)
 *            config.savedTasks.summary.template (task with a step graph)
 *   params — codes resolved via config.savedTasks.summary.{when|reads|saves}.<code>,
 *            plus raw interpolation values (at / tz / minutes).
 */
final class SavedTaskSummary
{
    public const KEY_SIMPLE = 'config.savedTasks.summary.simple';
    public const KEY_WITH_STEPS = 'config.savedTasks.summary.template';

    /**
     * @return array{key: string, params: array<string, string>}
     */
    public function describe(SavedTask $task): array
    {
        $params = $this->whenParams($task);

        $graph = $task->getGraph();
        $nodes = is_array($graph) && is_array($graph['nodes'] ?? null) ? $graph['nodes'] : null;
        if (null === $nodes) {
            return ['key' => self::KEY_SIMPLE, 'params' => $params];
        }

        $caps = [];
        foreach ($nodes as $node) {
            if (is_array($node) && is_string($node['capability'] ?? null)) {
                $caps[] = $node['capability'];
            }
        }

        $params['reads'] = in_array('email_search', $caps, true) ? 'mailbox' : 'instruction';
        $params['saves'] = match (true) {
            in_array('email_me', $caps, true) => 'email',
            in_array('save_to_folder', $caps, true) => 'folder',
            in_array('calendar_event', $caps, true) => 'calendar',
            default => 'reply',
        };

        return ['key' => self::KEY_WITH_STEPS, 'params' => $params];
    }

    /**
     * @return array<string, string>
     */
    private function whenParams(SavedTask $task): array
    {
        if (SavedTask::TRIGGER_INBOUND_EMAIL === $task->getTriggerType()) {
            return ['when' => 'inboundEmail'];
        }
        if (SavedTask::TRIGGER_CHAT === $task->getTriggerType()) {
            return ['when' => 'chat'];
        }
        if (SavedTask::TRIGGER_SCHEDULE !== $task->getTriggerType()) {
            return ['when' => 'manual'];
        }

        $config = $task->getTriggerConfig() ?? [];
        $at = is_string($config['at'] ?? null) ? $config['at'] : '';
        $tz = is_string($config['tz'] ?? null) ? $config['tz'] : 'UTC';

        return match ($config['kind'] ?? '') {
            'interval' => ['when' => 'interval', 'minutes' => (string) (int) ($config['every_minutes'] ?? 60)],
            'daily' => ['when' => 'daily', 'at' => $at, 'tz' => $tz],
            'weekly' => ['when' => 'weekly', 'at' => $at, 'tz' => $tz],
            default => ['when' => 'schedule'],
        };
    }
}
