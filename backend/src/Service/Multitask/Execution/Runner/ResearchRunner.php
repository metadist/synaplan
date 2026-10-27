<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunService;
use Psr\Log\LoggerInterface;

/**
 * Starts a quick or standard research for a saved task and pauses the task
 * until the report is ready. The research units run on their own worker.
 * A resume passes the report, or the sentence why it did not finish.
 */
final readonly class ResearchRunner implements TaskRunner
{
    public function __construct(
        private ResearchRunService $runs,
        private UserRepository $users,
        private ResearchConfig $research,
        private MultitaskRoutingConfig $routing,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::Research];
    }

    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::Research,
                'Research a question on the web and in the person\'s files, then hand the report to the next step. It does not stop to ask. Only a saved task can use it, and only at quick or standard depth.',
                enabledFlag: MultitaskRoutingConfig::KEY_RESEARCH_ENABLED,
                enabledDefault: false,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $settled = $this->settled($node);
        if (null !== $settled) {
            return $settled;
        }

        return $this->start($node, $context);
    }

    private function settled(TaskNode $node): ?NodeResult
    {
        $failed = $node->params['research_failed'] ?? null;
        if (is_string($failed) && '' !== trim($failed)) {
            return NodeResult::failed(trim($failed));
        }
        $report = $node->params['research_report'] ?? null;
        if (!is_string($report) || '' === trim($report)) {
            return null;
        }

        return NodeResult::ok(trim($report), $this->files($node), ['research' => true]);
    }

    private function start(TaskNode $node, NodeContext $context): NodeResult
    {
        if (!$this->routing->isFeatureEnabled(MultitaskRoutingConfig::KEY_RESEARCH_ENABLED, $context->userId, false)) {
            return NodeResult::failed('Research is turned off for saved tasks, so this step did not start.');
        }
        if (!$this->research->isEnabled()) {
            return NodeResult::failed('Research is turned off, so this step did not start.');
        }
        $taskRunId = $this->taskRunId($context);
        if (null === $taskRunId) {
            return NodeResult::failed('This step runs inside a saved task. Research did not start.');
        }
        $question = $this->question($node, $context);
        if ('' === $question) {
            return NodeResult::failed('Write what this step should find out.');
        }
        $user = null !== $context->userId ? $this->users->find($context->userId) : null;
        if (!$user instanceof User) {
            return NodeResult::failed('Sign in to run this step.');
        }
        $chatId = $context->message->getChat()?->getId();
        if (null === $chatId || $chatId < 1) {
            return NodeResult::failed('Choose the chat the report should go into.');
        }

        try {
            $state = $this->runs->startForSavedTask($user, $question, $this->depth($node), $chatId, $taskRunId, $node->id);
        } catch (ResearchRejected $rejected) {
            return NodeResult::failed($rejected->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Saved task research did not start', ['exception' => $e]);

            return NodeResult::failed('Research did not start. Nothing was searched.');
        }

        return NodeResult::waitingApproval(0, [], [
            'research' => ['run_id' => $state->ref->id],
        ]);
    }

    private function question(TaskNode $node, NodeContext $context): string
    {
        $inputs = $context->resolveInputs($node);
        foreach ([
            $node->params['question'] ?? null,
            $inputs['question'] ?? null,
            $inputs['query'] ?? null,
            $inputs['prompt'] ?? null,
            $inputs['text'] ?? null,
            $context->message->getText(),
        ] as $candidate) {
            if (is_string($candidate) && '' !== trim($candidate)) {
                return trim($candidate);
            }
        }

        return '';
    }

    private function depth(TaskNode $node): string
    {
        $depth = $node->params['depth'] ?? 'standard';

        return is_string($depth) && '' !== $depth ? $depth : 'standard';
    }

    private function taskRunId(NodeContext $context): ?int
    {
        $id = $context->options['saved_task_run_id'] ?? null;
        if (is_int($id) && $id > 0) {
            return $id;
        }
        if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
            return (int) $id;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function files(TaskNode $node): array
    {
        $raw = $node->params['research_files'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $files = [];
        foreach ($raw as $file) {
            if (is_array($file) && is_string($file['path'] ?? null) && '' !== $file['path']) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
