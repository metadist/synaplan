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
    /**
     * @var list<array{content: string, calls: list<array<string, mixed>>, results: list<array{id: string, name: string, content: string}>}>
     */
    private array $turns = [];

    private ?string $reminder = null;

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
        }
    }

    public function wasReminded(): bool
    {
        return null !== $this->reminder;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messagesFor(int $inputBudgetTokens, string $ledgerSummary): array
    {
        $clear = $this->overBudget($inputBudgetTokens);
        $last = count($this->turns) - 1;
        $messages = [];
        $reminded = null === $this->reminder;
        foreach ($this->turns as $index => $turn) {
            $messages[] = [
                'role' => 'assistant',
                'content' => $turn['content'],
                'tool_calls' => $turn['calls'],
            ];
            foreach ($turn['results'] as $result) {
                $content = $clear && $index < $last ? $ledgerSummary : $result['content'];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $result['id'],
                    'name' => $result['name'],
                    'content' => $content,
                ];
            }
            // The reminder answers the first turn without a tool call, so two assistant messages never follow each other.
            if (!$reminded && [] === $turn['calls']) {
                $messages[] = ['role' => 'user', 'content' => $this->reminder];
                $reminded = true;
            }
        }
        if (!$reminded && null !== $this->reminder) {
            $messages[] = ['role' => 'user', 'content' => $this->reminder];
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

        return $conversation;
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
