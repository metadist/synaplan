<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Model\ProviderTranscript;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The provider requests of one signed-in web turn, kept only until the live
 * response is written. Reset between requests so a worker cannot leak it.
 */
final class ProviderTranscriptCapture implements ResetInterface
{
    /** @var list<array{role: string, text: string}> */
    private array $messages = [];

    private int $used = 0;

    private bool $truncated = false;

    private bool $enabled = false;

    private ?int $lastStart = null;

    public function reset(): void
    {
        $this->messages = [];
        $this->used = 0;
        $this->truncated = false;
        $this->enabled = false;
        $this->lastStart = null;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function record(array $messages): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->lastStart = count($this->messages);
        $this->append($messages);
    }

    /**
     * The provider reduced or restored the request after {@see record()}.
     * Keep what was actually sent.
     *
     * @param list<array<string, mixed>> $messages
     */
    public function replaceLast(array $messages): void
    {
        if (!$this->enabled) {
            return;
        }
        if (null === $this->lastStart) {
            $this->record($messages);

            return;
        }
        $this->messages = array_slice($this->messages, 0, $this->lastStart);
        $this->rebuildUsed();
        $this->append($messages);
    }

    /**
     * Records an OpenAI Responses API request (`instructions` + `input`),
     * replacing the pre-provider snapshot of the current round.
     *
     * @param array<string, mixed> $requestOptions
     */
    public function recordResponsesRequest(array $requestOptions): void
    {
        if (!$this->enabled) {
            return;
        }

        $messages = [];
        $instructions = $requestOptions['instructions'] ?? null;
        if (is_string($instructions) && '' !== trim($instructions)) {
            $messages[] = ['role' => 'instructions', 'content' => $instructions];
        }

        $input = $requestOptions['input'] ?? null;
        if (is_array($input) && array_is_list($input)) {
            foreach ($input as $row) {
                if (is_array($row)) {
                    $messages[] = $row;
                }
            }
        }

        $this->replaceLast($messages);
    }

    /**
     * Records an Anthropic Messages API request after system extraction,
     * empty-turn filtering, and consecutive-role merging.
     *
     * @param list<array<string, mixed>> $conversationMessages
     */
    public function recordAnthropicRequest(?string $systemMessage, array $conversationMessages): void
    {
        if (!$this->enabled) {
            return;
        }

        $messages = [];
        if (null !== $systemMessage && '' !== trim($systemMessage)) {
            $messages[] = ['role' => 'instructions', 'content' => $systemMessage];
        }
        foreach ($conversationMessages as $row) {
            $messages[] = $row;
        }

        $this->replaceLast($messages);
    }

    /**
     * @return array{messages: list<array{role: string, text: string}>, truncated: bool}|null
     */
    public function payload(): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        return [
            'messages' => $this->messages,
            'truncated' => $this->truncated,
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function append(array $messages): void
    {
        $remaining = ProviderTranscript::MAX_CHARS - $this->used;
        if ($remaining <= 0) {
            $this->truncated = true;

            return;
        }
        $transcript = ProviderTranscript::fromMessages($messages)->redact()->cap($remaining);
        foreach ($transcript->toArray() as $row) {
            $this->messages[] = $row;
            $this->used += mb_strlen($row['text']);
        }
        if ($transcript->isTruncated()) {
            $this->truncated = true;
        }
    }

    private function rebuildUsed(): void
    {
        $this->used = 0;
        foreach ($this->messages as $row) {
            $this->used += mb_strlen($row['text']);
        }
        if ($this->used < ProviderTranscript::MAX_CHARS) {
            $this->truncated = false;
        }
    }
}
