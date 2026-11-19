<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Message;
use App\Service\Message\SpokenInput;

/**
 * What a turn offers as proof of an event's time, besides the steps of its
 * own plan: the latest message, what the person wrote earlier in the
 * conversation, and the text of the files they attached.
 *
 * A start time the planner wrote is the person's when they named a clock
 * time in the latest message, or named that very hour and minute earlier.
 * In every other case the hour is not theirs, and the entries have to come
 * from what was read. The planner and the runner ask the same object, so a
 * plan is never kept for a reason the runner would refuse.
 */
final readonly class CalendarTurnEvidence
{
    /** Enough for a booking, an invitation or a timetable page. */
    private const MAX_FILE_CHARS = 24000;

    private const MAX_EARLIER_CHARS = 8000;

    public function __construct(
        public string $messageText = '',
        public string $earlierUserText = '',
        public string $fileText = '',
    ) {
    }

    /**
     * Files attached to this message come first. Without any, the files the
     * person attached earlier in the conversation count, newest first: a
     * follow-up such as "it is all in the picture" refers to those.
     *
     * @param array<int, mixed> $thread oldest first; Message entities, or `{role, content}` rows
     */
    public static function ofTurn(Message $message, array $thread): self
    {
        $earlierTexts = [];
        $earlierFiles = [];
        foreach ($thread as $entry) {
            if ($entry instanceof Message) {
                if ('IN' !== $entry->getDirection() || self::isSame($entry, $message)) {
                    continue;
                }
                $earlierTexts[] = trim($entry->getText());
                $earlierFiles[] = self::attachedText($entry);
                continue;
            }
            if (is_array($entry) && 'user' === ($entry['role'] ?? null) && is_string($entry['content'] ?? null)) {
                $earlierTexts[] = trim($entry['content']);
            }
        }

        $fileText = self::attachedText($message);
        if ('' === $fileText) {
            $fileText = implode("\n\n---\n\n", array_filter(array_reverse($earlierFiles), static fn (string $text): bool => '' !== $text));
        }

        return new self(
            trim($message->getText()),
            mb_substr(implode("\n", array_filter($earlierTexts, static fn (string $text): bool => '' !== $text)), -self::MAX_EARLIER_CHARS),
            mb_substr($fileText, 0, self::MAX_FILE_CHARS),
        );
    }

    /**
     * Whether the person named the time of this start: any clock time in the
     * latest message, or this hour and minute in an earlier one.
     */
    public function backsStart(string $start): bool
    {
        if (UserNamedClockTime::in($this->messageText)) {
            return true;
        }
        $start = trim($start);
        if ('' === $start || '' === $this->earlierUserText) {
            return false;
        }

        try {
            $time = new \DateTimeImmutable($start);
        } catch (\Throwable) {
            return false;
        }

        return ClockTimeEvidence::names($time, $this->earlierUserText);
    }

    public function hasFileText(): bool
    {
        return '' !== $this->fileText;
    }

    private static function isSame(Message $entry, Message $message): bool
    {
        if ($entry === $message) {
            return true;
        }
        $id = $entry->getId();

        return null !== $id && $id === $message->getId();
    }

    /**
     * Extracted text of a message's attachments. A voice note whose
     * transcript is the message itself is the person's words, not a file.
     */
    private static function attachedText(Message $message): string
    {
        $texts = [];
        if ($message->getFile() > 0 && !SpokenInput::isLegacySpokenAudio($message)) {
            $legacy = trim($message->getFileText());
            if ('' !== $legacy) {
                $texts[] = $legacy;
            }
        }
        foreach ($message->getFiles() as $file) {
            if (SpokenInput::isSpokenAudio($message, $file)) {
                continue;
            }
            $text = trim($file->getFileText());
            if ('' !== $text) {
                $texts[] = $text;
            }
        }

        return implode("\n\n---\n\n", $texts);
    }
}
