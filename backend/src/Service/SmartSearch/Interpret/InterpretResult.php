<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Interpret;

/**
 * What the AI tier made of a question. `targetIds` only ever holds ids the
 * caller sent as candidates, best first.
 */
final readonly class InterpretResult
{
    public const INTENTS = ['navigate', 'change_setting', 'run_command', 'find', 'answer'];
    public const OUTCOMES = ['ok', 'no_match', 'failed'];

    /**
     * @param 'ok'|'no_match'|'failed'                                       $outcome
     * @param 'navigate'|'change_setting'|'run_command'|'find'|'answer'|null $intent
     * @param list<string>                                                   $targetIds
     */
    public function __construct(
        public string $outcome,
        public ?string $intent,
        public array $targetIds,
        public ?string $answer,
    ) {
    }

    public static function failed(): self
    {
        return new self('failed', null, [], null);
    }

    /**
     * @return array{outcome: string, intent: ?string, targetIds: list<string>, answer: ?string}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'intent' => $this->intent,
            'targetIds' => $this->targetIds,
            'answer' => $this->answer,
        ];
    }
}
