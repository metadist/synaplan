<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatRunModel;
use App\Service\Chat\Loop\ChatRunModelFailed;
use App\Service\Chat\Loop\ChatRunReply;
use App\Service\Chat\Loop\ChatRunState;

/**
 * Answers model calls from a script, and records what each call was asked.
 * A call with a listener streams its text in small deltas, as a provider does.
 */
final class ScriptedChatRunModel implements ChatRunModel
{
    private const DELTA = 7;

    /** False plays a model that cannot stream: the listener hears nothing. */
    public bool $streams = true;

    /** @var list<bool> per call, whether it had a listener */
    public array $listened = [];

    /** @var list<array{messages: list<array<string, mixed>>, tools: list<array<string, mixed>>, choice: ?string}> */
    public array $calls = [];

    /** @var list<ChatRunReply|ChatRunModelFailed> */
    private array $script;

    public function __construct(ChatRunReply|ChatRunModelFailed ...$script)
    {
        $this->script = array_values($script);
    }

    public function call(ChatRunState $state, array $messages, array $tools, ?string $toolChoice, ?callable $onText = null): ChatRunReply
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools, 'choice' => $toolChoice];
        $this->listened[] = null !== $onText;
        $next = array_shift($this->script);
        if (null === $next) {
            throw new \LogicException('The model was called more often than scripted.');
        }
        if ($next instanceof ChatRunModelFailed) {
            throw $next;
        }
        if (null !== $onText && $this->streams) {
            foreach (mb_str_split($next->content, self::DELTA) as $delta) {
                $onText($delta);
            }
        }

        return $next;
    }

    /** @return list<string> the tool names offered on call $i */
    public function offered(int $i): array
    {
        return array_map(static fn (array $tool): string => (string) $tool['function']['name'], $this->calls[$i]['tools']);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function calls(string $name, array $args = [], string $id = 'call_1'): ChatRunReply
    {
        return new ChatRunReply('', [['id' => $id, 'name' => $name, 'args' => $args]], 1_000);
    }

    public static function says(string $text): ChatRunReply
    {
        return new ChatRunReply($text, [], 1_000);
    }
}
