<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Recovery;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Tool\Source\SkillToolSource;
use Psr\Log\LoggerInterface;

/**
 * When a read comes back empty, try a wider query and stop at the first hit.
 *
 * At most two extra calls per step and four per turn. Writes are never
 * repeated. Each call goes through the same runner, so the safety gates run
 * again. The card and the answering step learn which wording was tried.
 */
final readonly class SearchRecovery
{
    /** @var list<Capability> */
    private const READS = [Capability::McpFetch, Capability::WebSearch, Capability::EmailSearch];

    /** @var list<string> */
    private const QUERY_KEYS = ['query', 'q', 'search', 'search_term', 'title', 'name', 'text', 'prompt'];

    public function __construct(
        private EmptyResultDetector $detector,
        private QueryRelaxer $relaxer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param (callable(string): void)|null $onRetry fired before each extra call, with the query label
     */
    public function recover(TaskRunner $runner, TaskNode $node, NodeContext $context, NodeResult $result, ?callable $onRetry = null, ?TaskPlan $plan = null): NodeResult
    {
        if (!in_array($node->capability, self::READS, true) || !$this->detector->isEmpty($result)) {
            return $result;
        }
        // A wider query can match a different item. When a write step uses
        // this result, that would change the wrong page, ticket or file.
        if (null !== $plan && self::feedsWrite($plan, $node->id)) {
            return $result;
        }
        $empty = $result;

        $userText = (string) $context->message->getText();
        $tool = is_string($node->params['tool'] ?? null) && '' !== trim($node->params['tool'])
            ? trim($node->params['tool'])
            : $node->capability->value;
        $original = $this->argumentsOf($node, $context);
        $tried = [QueryRelaxer::signature($original)];
        $attempts = [['query' => $this->label($original), 'hits' => 0]];

        // Two rungs, and only when this turn still has a recovery left.
        // A rung that repeats an earlier query is skipped, so it costs nothing.
        foreach (['suggest', 'broaden'] as $rung) {
            if (!$context->canSpendRecovery()) {
                break;
            }
            $next = 'suggest' === $rung
                ? $this->relaxer->suggest($userText, $tool, $original, $tried, $context->userId)
                : $this->relaxer->broaden($original, $userText, $tried);
            if (null === $next) {
                continue;
            }
            $signature = QueryRelaxer::signature($next);
            if (in_array($signature, $tried, true)) {
                continue;
            }
            $tried[] = $signature;
            $context->spendRecovery();

            $label = $this->label($next);
            $this->logger->info('SearchRecovery: retrying an empty read', [
                'node' => $node->id,
                'capability' => $node->capability->value,
                'rung' => $rung,
            ]);
            if (null !== $onRetry) {
                $onRetry($label);
            }

            $result = $runner->run($this->withArguments($node, $next), $context);
            if ($this->detector->isEmpty($result) || !$result->isSuccessful()) {
                $attempts[] = ['query' => $label, 'hits' => 0];
                continue;
            }

            $this->logger->info('SearchRecovery: broader query matched', [
                'node' => $node->id,
                'capability' => $node->capability->value,
            ]);

            return $this->annotate($result, $attempts, $label, $this->hits($result));
        }

        return $this->finishEmpty($empty, $attempts);
    }

    /**
     * Whether a write step depends on this node, directly or through steps
     * in between.
     */
    private static function feedsWrite(TaskPlan $plan, string $nodeId): bool
    {
        $sources = [$nodeId => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($plan->nodes as $candidate) {
                if (isset($sources[$candidate->id]) || [] === array_intersect($candidate->dependsOn, array_keys($sources))) {
                    continue;
                }
                if (in_array($candidate->capability->value, SkillToolSource::WRITE_CAPABILITIES, true)) {
                    return true;
                }
                $sources[$candidate->id] = true;
                $changed = true;
            }
        }

        return false;
    }

    /**
     * @param list<array{query: string, hits: int}> $attempts
     */
    private function annotate(NodeResult $result, array $attempts, string $matched, int $hits): NodeResult
    {
        $attempts[] = ['query' => $matched, 'hits' => $hits];

        return new NodeResult(
            $result->status,
            $result->text,
            $result->files,
            array_merge($result->metadata, [
                'recovery' => [
                    'attempts' => $attempts,
                    'matched_query' => $matched,
                    'empty' => false,
                ],
            ]),
            $result->error,
        );
    }

    /**
     * @param list<array{query: string, hits: int}> $attempts
     */
    private function finishEmpty(NodeResult $result, array $attempts): NodeResult
    {
        $metadata = $result->metadata;
        unset($metadata[NodeResult::META_REPORTABLE]);
        $metadata['recovery'] = [
            'attempts' => $attempts,
            'empty' => true,
        ];
        $text = $result->isSuccessful() ? $result->text : $result->error;

        return NodeResult::ok(is_string($text) && '' !== trim($text) ? $text : null, $result->files, $metadata);
    }

    private function hits(NodeResult $result): int
    {
        if (is_int($result->metadata['results_count'] ?? null) && $result->metadata['results_count'] > 0) {
            return $result->metadata['results_count'];
        }
        $listed = $result->metadata['search_results']['results'] ?? null;
        if (is_array($listed) && [] !== $listed) {
            return count($listed);
        }
        $decoded = json_decode(trim((string) $result->text), true);
        if (is_array($decoded) && !array_is_list($decoded)) {
            foreach (['results', 'items', 'data', 'hits', 'pages', 'issues', 'values'] as $key) {
                if (is_array($decoded[$key] ?? null) && [] !== $decoded[$key]) {
                    return count($decoded[$key]);
                }
            }
        }

        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function argumentsOf(TaskNode $node, NodeContext $context): array
    {
        // `$n1.text` must be the upstream text, not the literal reference.
        $inputs = $context->resolveInputs($node);
        if (Capability::McpFetch === $node->capability) {
            $arguments = $inputs['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }

            return is_array($arguments) ? $this->stringKeys($arguments) : [];
        }

        if (Capability::EmailSearch === $node->capability) {
            $query = $node->params['query'] ?? $inputs['query'] ?? $inputs['text'] ?? null;

            return ['query' => is_string($query) && '' !== trim($query) ? $query : (string) $context->message->getText()];
        }

        $query = $inputs['query'] ?? $inputs['prompt'] ?? $inputs['text'] ?? null;

        return ['query' => is_string($query) && '' !== trim($query) ? $query : (string) $context->message->getText()];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function withArguments(TaskNode $node, array $arguments): TaskNode
    {
        if (Capability::EmailSearch === $node->capability) {
            $params = $node->params;
            $params['query'] = $this->label($arguments);

            return new TaskNode($node->id, $node->capability, $node->dependsOn, $node->inputs, $params);
        }

        if (Capability::WebSearch === $node->capability) {
            $inputs = $node->inputs;
            $inputs['query'] = $this->label($arguments);

            return new TaskNode($node->id, $node->capability, $node->dependsOn, $inputs, $node->params);
        }

        $inputs = $node->inputs;
        $inputs['arguments'] = $arguments;

        return new TaskNode($node->id, $node->capability, $node->dependsOn, $inputs, $node->params);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function label(array $arguments): string
    {
        foreach (self::QUERY_KEYS as $key) {
            $value = $arguments[$key] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return mb_substr(trim($value), 0, 160);
            }
        }
        $json = json_encode($arguments, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return is_string($json) ? mb_substr($json, 0, 160) : 'search';
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function stringKeys(array $arguments): array
    {
        $out = [];
        foreach ($arguments as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
