<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;

/**
 * Pauses a run until the person picks an option or types an answer.
 * A resume passes `answer` in the node params and the node's text is that answer.
 */
final class AskUserRunner implements TaskRunner
{
    private const MAX_OPTIONS = 5;
    private const LIFETIME_SECONDS = 86400;

    public function supportedCapabilities(): array
    {
        return [Capability::AskUser];
    }

    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::AskUser,
                'Ask the person a question with up to five options (one may be recommended) and optional free text, then continue the same run with their answer.',
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $answer = $node->params['answer'] ?? null;
        if (is_string($answer) && '' !== trim($answer)) {
            return NodeResult::ok(trim($answer), [], ['ask_user_answered' => true]);
        }

        $question = trim((string) ($node->params['question'] ?? ''));
        if ('' === $question) {
            return NodeResult::failed('The question was empty, so nothing was asked.');
        }

        $options = [];
        $rawOptions = $node->params['options'] ?? [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $option) {
                if (!is_string($option)) {
                    continue;
                }
                $option = trim($option);
                if ('' === $option || in_array($option, $options, true)) {
                    continue;
                }
                $options[] = $option;
                if (count($options) >= self::MAX_OPTIONS) {
                    break;
                }
            }
        }
        $recommended = trim((string) ($node->params['recommended'] ?? ''));
        if ('' !== $recommended && !in_array($recommended, $options, true)) {
            $recommended = '';
        }

        return NodeResult::waitingApproval(0, [], [
            'ask_user' => [
                'question' => $question,
                'options' => $options,
                'recommended' => $recommended,
                'allowText' => false !== ($node->params['allow_text'] ?? true),
                'expiresAt' => time() + self::LIFETIME_SECONDS,
            ],
        ]);
    }
}
