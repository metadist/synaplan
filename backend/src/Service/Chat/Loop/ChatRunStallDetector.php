<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * Circle detection (03 §1.4): deterministic, no model call. It counts
 * repetition, not work, so a run that keeps finding new things never stops
 * here, however long it runs.
 *
 * The first signal tells the model what repeated and resets the counters.
 * The second ends the run with a wrap-up.
 */
final readonly class ChatRunStallDetector
{
    /** @var array{calls: array<string, array{tool: string, n: int}>, seen: array<string, true>, stale: int, errors: int, nudged: bool, found: bool, ran: bool} */
    public const EMPTY = ['calls' => [], 'seen' => [], 'stale' => 0, 'errors' => 0, 'nudged' => false, 'found' => false, 'ran' => false];

    /** Results remembered per run; a very long run forgets the oldest. */
    private const MAX_SEEN = 2_000;

    public function __construct(private ChatRunLoopLimits $limits)
    {
    }

    /**
     * One tool call of the step under way and what it gave back.
     *
     * @param array{id: string, name: string, args: array<string, mixed>} $call
     */
    public function record(ChatRunState $state, array $call, ChatToolResult $result): void
    {
        $stall = $state->stall;
        $key = self::callKey($call);
        $stall['calls'][$key] = ['tool' => $call['name'], 'n' => ($stall['calls'][$key]['n'] ?? 0) + 1];
        if (\count($stall['calls']) > self::MAX_SEEN) {
            $stall['calls'] = \array_slice($stall['calls'], -self::MAX_SEEN, null, true);
        }
        $stall['errors'] = $result->failed ? $stall['errors'] + 1 : 0;
        $text = trim($result->text);
        $seen = 'o:'.sha1($call['name']."\n".$text);
        if (!$result->failed && '' !== $text && !isset($stall['seen'][$seen])) {
            $stall['found'] = true;
        }
        $stall['seen'][$seen] = true;
        if (\count($stall['seen']) > self::MAX_SEEN) {
            $stall['seen'] = \array_slice($stall['seen'], -self::MAX_SEEN, null, true);
        }
        $stall['ran'] = true;
        $state->stall = $stall;
    }

    /** The step's tool calls all ran: count it as a step with or without anything new. */
    public function endStep(ChatRunState $state): void
    {
        $stall = $state->stall;
        if (!$stall['ran']) {
            return;
        }
        $stall['stale'] = $stall['found'] ? 0 : $stall['stale'] + 1;
        $stall['found'] = false;
        $stall['ran'] = false;
        $state->stall = $stall;
    }

    /**
     * Before the next model call: nudge once, then ask for the wrap-up.
     *
     * @return bool true when the run now wraps up
     */
    public function check(ChatRunState $state, ChatRunEvents $events): bool
    {
        $signal = $this->signal($state->stall);
        if (null === $signal) {
            return false;
        }
        if ($state->stall['nudged']) {
            return true;
        }
        $state->stall = [...self::EMPTY, 'seen' => $state->stall['seen'], 'nudged' => true];
        $state->messages[] = ['role' => 'user', 'content' => 'You are going in circles: '.$signal.' Use what you already have, or change the approach.'];
        $events->append($state->runId, ChatRunEvents::CIRCLING, ['nudge' => true]);

        return false;
    }

    /**
     * @param array<string, mixed> $stall
     *
     * @return array<string, mixed>
     */
    public static function encode(array $stall): array
    {
        return [...$stall, 'calls' => (object) $stall['calls'], 'seen' => array_keys($stall['seen'])];
    }

    /**
     * @return array{calls: array<string, array{tool: string, n: int}>, seen: array<string, true>, stale: int, errors: int, nudged: bool, found: bool, ran: bool}
     */
    public static function decode(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return self::EMPTY;
        }
        $calls = [];
        foreach (\is_array($raw['calls'] ?? null) ? $raw['calls'] : [] as $key => $row) {
            if (\is_string($key) && \is_array($row) && \is_string($row['tool'] ?? null) && \is_int($row['n'] ?? null) && $row['n'] > 0) {
                $calls[$key] = ['tool' => $row['tool'], 'n' => $row['n']];
            }
        }
        $seen = [];
        foreach (\is_array($raw['seen'] ?? null) ? $raw['seen'] : [] as $hash) {
            if (\is_string($hash)) {
                $seen[$hash] = true;
            }
        }

        return [
            'calls' => \array_slice($calls, -self::MAX_SEEN, null, true),
            'seen' => \array_slice($seen, -self::MAX_SEEN, null, true),
            'stale' => max(0, (int) ($raw['stale'] ?? 0)),
            'errors' => max(0, (int) ($raw['errors'] ?? 0)),
            'nudged' => true === ($raw['nudged'] ?? false),
            'found' => true === ($raw['found'] ?? false),
            'ran' => true === ($raw['ran'] ?? false),
        ];
    }

    /**
     * What repeated, in words for the model, or null.
     *
     * @param array{calls: array<string, array{tool: string, n: int}>, seen: array<string, true>, stale: int, errors: int, nudged: bool, found: bool, ran: bool} $stall
     */
    private function signal(array $stall): ?string
    {
        foreach ($stall['calls'] as $row) {
            if ($row['n'] >= $this->limits->repeat()) {
                return 'you called '.$row['tool'].' with the same input '.$row['n'].' times.';
            }
        }
        if ($stall['stale'] >= $this->limits->noNew()) {
            return 'the last '.$stall['stale'].' steps brought nothing new.';
        }
        if ($stall['errors'] >= $this->limits->errors()) {
            return 'the last '.$stall['errors'].' tool calls failed.';
        }

        return null;
    }

    /**
     * The call by its normalised input: keys sorted, text trimmed, lower
     * case and single spaces, so a cosmetic change is still the same call.
     *
     * @param array{id: string, name: string, args: array<string, mixed>} $call
     */
    private static function callKey(array $call): string
    {
        return 'c:'.sha1($call['name']."\n".json_encode(self::normalise($call['args']), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function normalise(mixed $value): mixed
    {
        if (\is_string($value)) {
            return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
        }
        if (!\is_array($value)) {
            return $value;
        }
        $out = array_map(self::normalise(...), $value);
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
