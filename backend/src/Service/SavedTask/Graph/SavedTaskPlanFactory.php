<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Entity\SavedTask;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlan;

final class SavedTaskPlanFactory
{
    public function __construct(
        private SavedTaskGraphValidator $validator,
    ) {
    }

    public function fromTask(SavedTask $task, string $language = 'en'): TaskPlan
    {
        $graph = $task->getGraph();
        if (null === $graph) {
            $promptTopic = $task->getTriggerConfig()['prompt_topic'] ?? null;

            return TaskPlan::singleChatPlan(
                $language,
                is_string($promptTopic) ? $promptTopic : null,
                (string) $task->getPromptId(),
            );
        }

        $errors = $this->validator->validate($graph, $task->getTriggerType(), $task->getTriggerConfig());
        if ([] !== $errors) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }

        $nodes = $graph['nodes'] ?? [];
        if (!is_array($nodes) || [] === $nodes) {
            throw new \InvalidArgumentException('graph has no steps');
        }

        $tasks = [];
        $reply = null;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = (string) $node['id'];
            $capability = (string) $node['capability'];
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            if (Capability::Chat->value === $capability && !isset($params['topic_id']) && isset($graph['prompt_topic'])) {
                $params['topic_id'] = $graph['prompt_topic'];
            }
            if (Capability::Chat->value === $capability && !isset($params['prompt_id'])) {
                $params['prompt_id'] = (string) $task->getPromptId();
            }
            $tasks[] = [
                'id' => $id,
                'capability' => $capability,
                'depends_on' => array_values(array_filter($node['depends_on'] ?? [], 'is_string')),
                'params' => $params,
            ];
            if (Capability::ComposeReply->value === $capability) {
                $reply = $id;
            }
        }

        $reply ??= (string) $tasks[array_key_last($tasks)]['id'];

        return TaskPlan::fromArray([
            'version' => 1,
            'language' => $language,
            'reply_node' => $reply,
            'tasks' => $tasks,
        ]);
    }
}
