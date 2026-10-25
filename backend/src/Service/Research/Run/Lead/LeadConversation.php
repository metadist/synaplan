<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

/**
 * The lead's tool turns, kept with the run so a lost worker repeats at most
 * the turn that had not been saved. What the model sees drops older tool
 * results once they pass 60% of its input budget. The stored turns stay
 * whole, and the tool-call records stay in both.
 */
final class LeadConversation
{
    private const CLEARED = '{"cleared":true,"note":"Removed to save space. The ledger summary in a later result covers it."}';

    /**
     * @var list<array{content: string, calls: list<array<string, mixed>>, results: list<array{id: string, name: string, content: string}>}>
     */
    private array $turns = [];

    private ?string $reminder = null;

    /** Index of the turn the reminder answered. */
    private int $reminderAfter = -1;

    /**
     * @param list<array<string, mixed>>                             $calls
     * @param list<array{id: string, name: string, content: string}> $results
     */
    public function append(string $content, array $calls, array $results): void
    {
        $this->turns[] = [
            'content' => $content,
            'calls' => $calls,
            'results' => $results,
        ];
    }

    public function remind(string $text): void
    {
        if (null === $this->reminder) {
            $this->reminder = $text;
            $this->reminderAfter = count($this->turns) - 1;
        }
    }

    public function wasReminded(): bool
    {
        return null !== $this->reminder;
    }

    /**
     * @return list<array{content: string, calls: list<array<string, mixed>>, results: list<array{id: string, name: string, content: string}>}>
     */
    public function turns(): array
    {
        return $this->turns;
    }

    /**
     * Providers reject an assistant message with an empty `tool_calls` list,
     * and Anthropic rejects one with empty text, so such turns are left out.
     * Past the budget, every older tool result but one becomes a short
     * marker and the last older one carries the ledger summary once.
     *
     * @return list<array<string, mixed>>
     */
    public function messagesFor(int $inputBudgetTokens, string $ledgerSummary): array
    {
        $clear = $this->overBudget($inputBudgetTokens);
        $last = count($this->turns) - 1;
        $summaryAt = $clear ? $this->lastOlderResult($last) : null;
        $messages = [];
        foreach ($this->turns as $index => $turn) {
            $message = ['role' => 'assistant', 'content' => $turn['content']];
            if ([] !== $turn['calls']) {
                $message['tool_calls'] = $turn['calls'];
            }
            if ([] !== $turn['calls'] || '' !== trim($turn['content'])) {
                $messages[] = $message;
            }
            foreach ($turn['results'] as $position => $result) {
                $content = $result['content'];
                if ($clear && $index < $last) {
                    $content = [$index, $position] === $summaryAt ? $ledgerSummary : self::CLEARED;
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $result['id'],
                    'name' => $result['name'],
                    'content' => $content,
                ];
            }
            if (null !== $this->reminder && $index === $this->reminderAfter) {
                $messages[] = ['role' => 'user', 'content' => $this->reminder];
            }
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'turns' => $this->turns,
            'reminder' => $this->reminder,
            'reminder_after' => $this->reminderAfter,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $conversation = new self();
        $turns = $data['turns'] ?? null;
        if (is_array($turns)) {
            foreach ($turns as $turn) {
                if (!is_array($turn)) {
                    continue;
                }
                $conversation->turns[] = [
                    'content' => is_string($turn['content'] ?? null) ? $turn['content'] : '',
                    'calls' => self::calls($turn['calls'] ?? null),
                    'results' => self::results($turn['results'] ?? null),
                ];
            }
        }
        $reminder = $data['reminder'] ?? null;
        $conversation->reminder = is_string($reminder) && '' !== $reminder ? $reminder : null;
        $after = $data['reminder_after'] ?? null;
        $conversation->reminderAfter = is_int($after) ? $after : $conversation->firstTurnWithoutCalls();

        return $conversation;
    }

    /** Where a reminder stored before `reminder_after` existed belongs. */
    private function firstTurnWithoutCalls(): int
    {
        foreach ($this->turns as $index => $turn) {
            if ([] === $turn['calls']) {
                return $index;
            }
        }

        return count($this->turns) - 1;
    }

    /**
     * @return array{int, int}|null turn index and result position
     */
    private function lastOlderResult(int $last): ?array
    {
        for ($index = $last - 1; $index >= 0; --$index) {
            $count = count($this->turns[$index]['results']);
            if ($count > 0) {
                return [$index, $count - 1];
            }
        }

        return null;
    }

    private function overBudget(int $inputBudgetTokens): bool
    {
        $chars = 0;
        foreach ($this->turns as $turn) {
            foreach ($turn['results'] as $result) {
                $chars += mb_strlen($result['content']);
            }
        }

        return intdiv($chars, 4) > intdiv($inputBudgetTokens * 60, 100);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function calls(mixed $calls): array
    {
        if (!is_array($calls)) {
            return [];
        }
        $kept = [];
        foreach ($calls as $call) {
            if (is_array($call)) {
                $kept[] = $call;
            }
        }

        return $kept;
    }

    /**
     * @return list<array{id: string, name: string, content: string}>
     */
    private static function results(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }
        $kept = [];
        foreach ($results as $result) {
            if (!is_array($result) || !is_string($result['id'] ?? null) || !is_string($result['name'] ?? null)) {
                continue;
            }
            $kept[] = [
                'id' => $result['id'],
                'name' => $result['name'],
                'content' => is_string($result['content'] ?? null) ? $result['content'] : '',
            ];
        }

        return $kept;
    }
}
