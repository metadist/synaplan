<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

/**
 * One in-process research run: brief, plan, lead loop, one write.
 * No queue, no API, no checker model.
 */
final class SpikeEngine
{
    public function __construct(
        private readonly SpikeChat $chat,
        private readonly SpikeSources $sources,
        private readonly SpikeReader $reader,
        private readonly JsonSlice $json,
        private readonly PromptBook $prompts,
    ) {
    }

    public function answer(string $question, SpikeLimits $limits, string $mode, int $userId): SpikeAnswer
    {
        $board = new SpikeBoard($limits->searches, $limits->sources);
        $brief = $this->brief($question, $userId, $board);
        $plan = $this->plan($brief, $question, $userId, $board);
        $language = \is_string($brief['language'] ?? null) ? $brief['language'] : 'en';
        if ('tools' === $mode) {
            $this->toolLoop($question, $brief, $plan, $board, $limits, $language, $userId);
        } else {
            $this->roundLoop($brief, $plan, $board, $limits, $language, $userId);
        }

        return new SpikeAnswer(
            $this->write($brief, $plan, $board, $language, $userId),
            $limits->searches - $board->searchesLeft,
            $limits->sources - $board->readsLeft,
            $board->kept,
            $board->dropped,
            \count($board->sources),
            $board->readLog,
            $board->inputTokens,
            $board->outputTokens,
            $board->evidence(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function brief(string $question, int $userId, SpikeBoard $board): array
    {
        $turn = $this->speak([
            ['role' => 'system', 'content' => $this->prompts->fill('research_brief', ['TODAY' => gmdate('Y-m-d')])],
            ['role' => 'user', 'content' => $question],
        ], [], $userId, $board);
        $brief = $this->json->object($turn->content);
        if (!\is_string($brief['goal'] ?? null) || '' === trim($brief['goal'])) {
            $brief['goal'] = $question;
        }

        return $brief;
    }

    /**
     * @param array<string, mixed> $brief
     *
     * @return array<string, mixed>
     */
    private function plan(array $brief, string $question, int $userId, SpikeBoard $board): array
    {
        $turn = $this->speak([
            ['role' => 'system', 'content' => $this->prompts->fill('research_plan', [
                'TODAY' => gmdate('Y-m-d'),
                'BRIEF' => (string) json_encode($brief, JSON_UNESCAPED_UNICODE),
            ])],
            ['role' => 'user', 'content' => $question],
        ], [], $userId, $board);
        $plan = $this->json->object($turn->content);
        $points = $this->points($plan, $question);
        $plan['points'] = $points;

        return $plan;
    }

    /**
     * @param array<string, mixed> $brief
     * @param array<string, mixed> $plan
     */
    private function toolLoop(string $question, array $brief, array $plan, SpikeBoard $board, SpikeLimits $limits, string $language, int $userId): void
    {
        $messages = [
            ['role' => 'system', 'content' => $this->prompts->fill('research_lead', [
                'TODAY' => gmdate('Y-m-d'),
                'SEARCHES' => (string) $limits->searches,
                'READS' => (string) $limits->sources,
                'BRIEF' => (string) ($brief['goal'] ?? $question),
                'PLAN' => $this->planText($plan),
            ])],
            ['role' => 'user', 'content' => $question],
        ];
        $reminded = false;
        $rejectedFinish = false;
        for ($turn = 0; $turn < $limits->turns; ++$turn) {
            $reply = $this->speak($messages, $this->tools(), $userId, $board);
            if ([] === $reply->calls) {
                if ($reminded) {
                    return;
                }
                $reminded = true;
                $hint = 'none' === $board->unreadSummary()
                    ? 'Call finish with what you have.'
                    : 'Call read_sources with the unread source ids before finish.';
                $messages[] = ['role' => 'user', 'content' => $hint];
                continue;
            }
            $searchedThisTurn = false;
            foreach ($reply->calls as $call) {
                if ('search' === $call->name) {
                    $searchedThisTurn = true;
                }
            }
            $messages[] = [
                'role' => 'assistant',
                'content' => $reply->content,
                'tool_calls' => array_map(static fn (SpikeToolCall $call): array => $call->toMessage(), $reply->calls),
            ];
            $finishCalls = [];
            foreach ($reply->calls as $call) {
                if ('finish' === $call->name) {
                    $finishCalls[] = $call;
                    continue;
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->toMessage()['id'],
                    'content' => $this->toolResult($call, $board, $plan, $limits, $language, $userId),
                ];
            }
            foreach ($finishCalls as $call) {
                if ($searchedThisTurn || ('none' !== $board->unreadSummary() && [] === $board->cards && !$rejectedFinish)) {
                    $rejectedFinish = true;
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call->toMessage()['id'],
                        'content' => '{"error":"unread_sources","unread":'.$this->jsonList($board->unreadSummary()).'}',
                    ];
                    continue;
                }

                return;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     */
    private function speak(array $messages, array $tools, int $userId, SpikeBoard $board): SpikeTurn
    {
        $turn = $this->chat->complete($messages, $tools, $userId);
        $board->inputTokens += $turn->inputTokens;
        $board->outputTokens += $turn->outputTokens;

        return $turn;
    }

    private function jsonList(string $unread): string
    {
        return json_encode($unread, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $brief
     * @param array<string, mixed> $plan
     */
    private function roundLoop(array $brief, array $plan, SpikeBoard $board, SpikeLimits $limits, string $language, int $userId): void
    {
        $stalls = 0;
        $rejectedEmptyFinish = false;
        for ($turn = 0; $turn < $limits->turns; ++$turn) {
            $before = \count($board->cards) + \count($board->sources);
            $goal = (string) ($brief['goal'] ?? '');
            if ($rejectedEmptyFinish) {
                $goal .= 'none' === $board->unreadSummary()
                    ? "\nNo evidence yet and searches remain. Set finish to null and choose a search."
                    : "\nSources are unread. Set finish to null and choose a read.";
            }
            $reply = $this->speak([
                ['role' => 'system', 'content' => $this->prompts->fill('research_reflect', [
                    'TODAY' => gmdate('Y-m-d'),
                    'SEARCHES_LEFT' => (string) $board->searchesLeft,
                    'READS_LEFT' => (string) $board->readsLeft,
                    'PLAN' => $this->planText($plan),
                    'FOUND' => $board->foundSummary(),
                    'UNREAD' => $board->unreadSummary(),
                ])],
                ['role' => 'user', 'content' => $goal],
            ], [], $userId, $board);
            $decision = $this->json->object($reply->content);
            $finish = $decision['finish'] ?? null;
            if (\is_string($finish) && '' !== $finish && 'null' !== $finish) {
                if (!$rejectedEmptyFinish && [] === $board->cards && ($board->searchesLeft > 0 || 'none' !== $board->unreadSummary())) {
                    $rejectedEmptyFinish = true;
                    continue;
                }

                return;
            }
            $actions = \is_array($decision['actions'] ?? null) ? $decision['actions'] : [];
            if ([] === $actions) {
                ++$stalls;
                if ($stalls > 1) {
                    return;
                }
                continue;
            }
            $this->runActions($actions, $board, $plan, $limits, $language, $userId);
            if (\count($board->cards) + \count($board->sources) === $before) {
                ++$stalls;
                if ($stalls > 1) {
                    return;
                }
            } else {
                $stalls = 0;
            }
        }
    }

    /**
     * @param list<mixed>          $actions
     * @param array<string, mixed> $plan
     */
    private function runActions(array $actions, SpikeBoard $board, array $plan, SpikeLimits $limits, string $language, int $userId): void
    {
        foreach (\array_slice($actions, 0, 4) as $action) {
            if (!\is_array($action)) {
                continue;
            }
            $type = (string) ($action['type'] ?? '');
            if ('search' === $type && \is_string($action['query'] ?? null)) {
                $this->search($action['query'], $board, $limits, $userId, false);
            }
            if ('read' === $type && \is_string($action['source'] ?? null)) {
                $this->readIds([$action['source']], $board, $plan, $language, $userId, null);
            }
            if ('reread' === $type && \is_string($action['source'] ?? null)) {
                $question = \is_string($action['question'] ?? null) ? $action['question'] : null;
                $this->readIds([$action['source']], $board, $plan, $language, $userId, $question);
            }
        }
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function toolResult(SpikeToolCall $call, SpikeBoard $board, array $plan, SpikeLimits $limits, string $language, int $userId): string
    {
        if ('search' === $call->name) {
            $query = \is_string($call->arguments['query'] ?? null) ? $call->arguments['query'] : '';

            return $this->search($query, $board, $limits, $userId, true);
        }
        if ('read_sources' === $call->name || 'reread' === $call->name) {
            $ids = [];
            $raw = $call->arguments['ids'] ?? $call->arguments['source'] ?? [];
            foreach (\is_array($raw) ? $raw : [$raw] as $id) {
                if (\is_string($id)) {
                    $ids[] = $id;
                }
            }
            $sharper = \is_string($call->arguments['question'] ?? null) ? $call->arguments['question'] : null;

            return $this->readIds($ids, $board, $plan, $language, $userId, $sharper);
        }

        return '{"error":"unknown_tool"}';
    }

    private function search(string $query, SpikeBoard $board, SpikeLimits $limits, int $userId, bool $reportBudget): string
    {
        $query = trim($query);
        if ('' === $query) {
            return '{"error":"empty_query"}';
        }
        if ($board->hasQuery($query)) {
            return '{"error":"repeated_query"}';
        }
        if ($board->searchesLeft <= 0) {
            return $reportBudget
                ? json_encode(['error' => 'budget_used', 'left' => ['searches' => 0, 'sources' => $board->readsLeft]], JSON_THROW_ON_ERROR)
                : '{"error":"dropped"}';
        }
        --$board->searchesLeft;
        $board->rememberQuery($query);
        $hits = $this->sources->search($query, $limits->resultsPerSearch, $userId);
        $rows = [];
        foreach ($hits as $hit) {
            $id = $board->addHit($hit);
            $rows[] = ['id' => $id, 'title' => $board->sources[$id]['title'], 'url' => $hit->url];
        }

        return json_encode(['results' => $rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string>         $ids
     * @param array<string, mixed> $plan
     */
    private function readIds(array $ids, SpikeBoard $board, array $plan, string $language, int $userId, ?string $sharper): string
    {
        $cards = [];
        foreach ($ids as $id) {
            $source = $board->sources[$id] ?? null;
            if (null === $source) {
                $cards[] = ['source' => $id, 'error' => 'unknown_source'];
                continue;
            }
            if ($board->readsLeft <= 0) {
                return json_encode(['error' => 'budget_used', 'left' => ['searches' => $board->searchesLeft, 'sources' => 0], 'cards' => $cards], JSON_THROW_ON_ERROR);
            }
            --$board->readsLeft;
            $board->sources[$id]['read'] = true;
            $page = $this->sources->read($source['url']);
            $preview = preg_replace('/\s+/u', ' ', $page->text) ?? '';
            $board->readLog .= $id.' '.mb_strlen($page->text).' chars'
                .(null !== $page->blocked ? ' blocked='.$page->blocked : '')
                .' '.$source['url'].' '.mb_substr($preview, 0, 160)."\n";
            if (null !== $page->blocked || '' === trim($page->text)) {
                $cards[] = ['source' => $id, 'blocked' => $page->blocked ?? 'no_text'];
                continue;
            }
            $read = $this->reader->read($page->text, $page->links, $this->pointRefs($plan), $language, $userId, $sharper);
            $board->dropped += $read['dropped'];
            $board->kept += $read['kept'];
            $board->inputTokens += $read['inputTokens'];
            $board->outputTokens += $read['outputTokens'];
            if ([] === $read['cards']) {
                $board->readLog .= $id.' reader kept 0, dropped '.$read['dropped'].' '.$read['note']."\n";
            }
            foreach ($read['cards'] as $card) {
                $evidence = $board->addCard($card, $source['url']);
                $cards[] = ['id' => $evidence, 'fact' => $card['fact']];
            }
            foreach ($read['leads'] as $lead) {
                $board->addHit(new SearchHit($lead, $lead, 'lead'));
            }
        }

        return json_encode(['cards' => $cards], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $brief
     * @param array<string, mixed> $plan
     */
    private function write(array $brief, array $plan, SpikeBoard $board, string $language, int $userId): string
    {
        $outline = $plan['outline'] ?? [];
        $turn = $this->speak([
            ['role' => 'system', 'content' => $this->prompts->fill('research_write', [
                'LANGUAGE' => $language,
                'BRIEF' => (string) ($brief['goal'] ?? ''),
                'OUTLINE' => \is_array($outline) ? implode("\n", array_map('strval', $outline)) : '',
                'EVIDENCE' => $board->evidenceBlock(),
            ])],
            ['role' => 'user', 'content' => 'Write the answer from the evidence.'],
        ], [], $userId, $board);

        return trim($turn->content);
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return list<array{id: string, title: string, queries: list<string>}>
     */
    private function points(array $plan, string $question): array
    {
        $points = [];
        foreach ($plan['points'] ?? [] as $index => $point) {
            if (!\is_array($point)) {
                continue;
            }
            $queries = [];
            foreach ($point['queries'] ?? [] as $query) {
                if (\is_string($query) && '' !== trim($query)) {
                    $queries[] = trim($query);
                }
            }
            $points[] = [
                'id' => \is_string($point['id'] ?? null) ? $point['id'] : 'p'.($index + 1),
                'title' => \is_string($point['title'] ?? null) ? $point['title'] : 'Question',
                'queries' => $queries,
            ];
        }
        if ([] === $points) {
            $points[] = ['id' => 'p1', 'title' => 'Question', 'queries' => [$question]];
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return list<array{id: string, title: string}>
     */
    private function pointRefs(array $plan): array
    {
        $refs = [];
        foreach ($plan['points'] ?? [] as $point) {
            if (\is_array($point) && \is_string($point['id'] ?? null) && \is_string($point['title'] ?? null)) {
                $refs[] = ['id' => $point['id'], 'title' => $point['title']];
            }
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function planText(array $plan): string
    {
        return (string) json_encode($plan['points'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        $sourceId = ['type' => 'string', 'description' => 'A source id from a search result, like s1.'];

        return [
            $this->tool('search', 'Search the web for one query.', [
                'query' => ['type' => 'string', 'description' => 'Short search query with exact names and years.'],
            ], ['query']),
            $this->tool('read_sources', 'Read sources by id and get evidence cards back.', [
                'ids' => ['type' => 'array', 'items' => $sourceId, 'minItems' => 1],
            ], ['ids']),
            $this->tool('reread', 'Read one source again for a sharper question.', [
                'source' => $sourceId,
                'question' => ['type' => 'string', 'description' => 'What to look for this time.'],
            ], ['source', 'question']),
            $this->tool('finish', 'Stop gathering.', [
                'reason' => ['type' => 'string', 'enum' => ['covered', 'budget', 'no_progress']],
            ], ['reason']),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required): array
    {
        return ['type' => 'function', 'function' => [
            'name' => $name,
            'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
        ]];
    }
}
