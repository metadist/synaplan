<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * Inline buttons under bot replies. Callback data is `{action}:{messageId}`
 * with an optional `:{modelId}` and stays far below Telegram's 64 bytes.
 */
final class TelegramKeyboard
{
    public const AGAIN = 'a';
    public const MODELS = 'm';
    public const PICK_MODEL = 'p';
    public const BACK = 'b';
    public const NOT_CORRECT = 'f';
    public const FEEDBACK_CANCEL = 'x';
    public const CANCEL_JOB = 'c';

    private const ACTIONS = [self::AGAIN, self::MODELS, self::PICK_MODEL, self::BACK, self::NOT_CORRECT, self::FEEDBACK_CANCEL, self::CANCEL_JOB];
    private const MAX_DATA_BYTES = 64;
    private const MODELS_PER_ROW = 2;

    /**
     * @param callable(string): string $label translation of a button key
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function actions(int $messageId, callable $label): array
    {
        return ['inline_keyboard' => [[
            self::button($label('btn_again'), self::AGAIN, $messageId),
            self::button($label('btn_other_model'), self::MODELS, $messageId),
            self::button($label('btn_not_correct'), self::NOT_CORRECT, $messageId),
        ]]];
    }

    /**
     * @param array<int, string>       $models model id => name
     * @param callable(string): string $label
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function models(int $messageId, array $models, callable $label): array
    {
        $rows = [];
        $row = [];
        foreach ($models as $modelId => $name) {
            $row[] = self::button($name, self::PICK_MODEL, $messageId, $modelId);
            if (self::MODELS_PER_ROW === count($row)) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ([] !== $row) {
            $rows[] = $row;
        }
        $rows[] = [self::button($label('btn_back'), self::BACK, $messageId)];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param callable(string): string $label
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function cancelJob(int $messageId, callable $label): array
    {
        return ['inline_keyboard' => [[self::button($label('btn_cancel'), self::CANCEL_JOB, $messageId)]]];
    }

    /**
     * @param callable(string): string $label
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function cancelFeedback(int $messageId, callable $label): array
    {
        return ['inline_keyboard' => [[self::button($label('btn_cancel'), self::FEEDBACK_CANCEL, $messageId)]]];
    }

    /**
     * @return array{action: string, messageId: int, modelId: int|null}|null
     */
    public static function parse(mixed $data): ?array
    {
        if (!is_string($data) || strlen($data) > self::MAX_DATA_BYTES) {
            return null;
        }
        if (!preg_match('/^([a-z]):([1-9]\d{0,18})(?::([1-9]\d{0,18}))?$/D', $data, $matches)) {
            return null;
        }
        if (!in_array($matches[1], self::ACTIONS, true)) {
            return null;
        }
        $modelId = isset($matches[3]) ? (int) $matches[3] : null;
        if ((self::PICK_MODEL === $matches[1]) !== (null !== $modelId)) {
            return null;
        }

        return ['action' => $matches[1], 'messageId' => (int) $matches[2], 'modelId' => $modelId];
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private static function button(string $text, string $action, int $messageId, ?int $modelId = null): array
    {
        $data = $action.':'.$messageId.(null !== $modelId ? ':'.$modelId : '');

        return ['text' => mb_substr($text, 0, 64), 'callback_data' => $data];
    }
}
