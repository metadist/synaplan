<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\Chat;
use App\Entity\Message;
use App\Service\Infrastructure\PerfTimer;

/**
 * The working set of one streamed turn, filled step by step.
 *
 * A step that fails halfway leaves what it already created here, so the
 * error path can still store an error reply next to the incoming message.
 */
final class StreamTurnState
{
    public ?Chat $chat = null;

    public ?Message $incoming = null;

    /** The cut-off answer a continuation appends to. */
    public ?Message $continued = null;

    /** The quoted source message, once it is proven to belong to this chat and person. */
    public ?int $quotedMessageId = null;

    /** What the turn processes: the person's text, or the continuation request. */
    public string $messageText;

    /** The chat id as sent; once files were attached, the reloaded message's chat id. */
    public int|string|null $chatId;

    /**
     * @param array{id: ?int, name: ?string, provider: ?string} $intendedChat the model the person picked, shown on an error reply
     */
    public function __construct(
        StreamTurnRequest $turn,
        public readonly PerfTimer $perfTimer,
        public readonly array $intendedChat,
    ) {
        $this->messageText = $turn->input->message;
        $this->chatId = $turn->chatId;
    }

    public function incoming(): Message
    {
        return $this->incoming ?? throw new \LogicException('The incoming message of this turn does not exist yet.');
    }
}
