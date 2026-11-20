<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\SavedTask;
use App\Service\Multitask\Plan\Capability;

/**
 * Where a Saved Task run's result goes — the one place that answers it.
 *
 * The target is derived, never stored twice: an authored step that sends or
 * saves (an `email_me` node, a folder, Notion, a calendar entry) wins, then
 * the `delivery` key the schedule carries, and the default is the reply in
 * the task's own chat. Choosing a target through {@see apply()} normalizes
 * the graph: a result mailed by the runner must not also be mailed by an
 * `email_me` step, so that step is removed when a target is chosen.
 */
final class SavedTaskDelivery
{
    public const CHAT = 'chat';
    public const EMAIL = 'email';

    /** Delivery targets a person can pick when scheduling. */
    public const CHOICES = [self::CHAT, self::EMAIL];

    /**
     * Language-neutral code for the card sentence (SavedTaskSummary `saves`):
     * email / folder / notion / calendar / reply.
     */
    public function summaryCode(SavedTask $task): string
    {
        $caps = $this->capabilities($task);

        return match (true) {
            in_array(Capability::EmailMe->value, $caps, true), self::EMAIL === $this->chosen($task) => 'email',
            in_array(Capability::SaveToFolder->value, $caps, true) => 'folder',
            in_array(Capability::SaveToNotion->value, $caps, true) => 'notion',
            in_array(Capability::CalendarEvent->value, $caps, true) => 'calendar',
            default => 'reply',
        };
    }

    /**
     * The runner mails the reply itself when the schedule chose email and no
     * step already does it. A step that mails keeps its own path (inputs,
     * attachments), so the runner must not send a second copy.
     */
    public function runnerMailsTheReply(SavedTask $task): bool
    {
        return self::EMAIL === $this->chosen($task)
            && !in_array(Capability::EmailMe->value, $this->capabilities($task), true);
    }

    /**
     * Records the person's choice on the task and drops any `email_me` step:
     * from now on the chosen target is the only mail path for this task.
     */
    public function apply(SavedTask $task, string $target): void
    {
        if (!in_array($target, self::CHOICES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown delivery target "%s"', $target));
        }

        $config = $task->getTriggerConfig() ?? [];
        if (self::EMAIL === $target) {
            $config['delivery'] = self::EMAIL;
        } else {
            unset($config['delivery']);
        }
        $task->setTrigger($task->getTriggerType(), $config);

        $this->dropMailSteps($task);
    }

    private function chosen(SavedTask $task): ?string
    {
        $delivery = $task->getTriggerConfig()['delivery'] ?? null;

        return is_string($delivery) && in_array($delivery, self::CHOICES, true) ? $delivery : null;
    }

    /**
     * @return list<string>
     */
    private function capabilities(SavedTask $task): array
    {
        $graph = $task->getGraph();
        $nodes = is_array($graph) && is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];

        $caps = [];
        foreach ($nodes as $node) {
            if (is_array($node) && is_string($node['capability'] ?? null)) {
                $caps[] = $node['capability'];
            }
        }

        return $caps;
    }

    private function dropMailSteps(SavedTask $task): void
    {
        $graph = $task->getGraph();
        if (!is_array($graph) || !is_array($graph['nodes'] ?? null)) {
            return;
        }

        $removed = [];
        $nodes = [];
        foreach ($graph['nodes'] as $node) {
            if (is_array($node) && Capability::EmailMe->value === ($node['capability'] ?? null)) {
                if (is_string($node['id'] ?? null)) {
                    $removed[] = $node['id'];
                }
                continue;
            }
            $nodes[] = $node;
        }
        if ([] === $removed) {
            return;
        }

        foreach ($nodes as $i => $node) {
            if (!is_array($node) || !is_array($node['depends_on'] ?? null)) {
                continue;
            }
            $nodes[$i]['depends_on'] = array_values(array_filter(
                $node['depends_on'],
                static fn (mixed $dep): bool => !in_array($dep, $removed, true),
            ));
        }
        if (isset($graph['reply_node']) && in_array($graph['reply_node'], $removed, true)) {
            unset($graph['reply_node']);
        }
        $graph['nodes'] = $nodes;
        $task->setGraph([] === $nodes ? null : $graph);
    }
}
