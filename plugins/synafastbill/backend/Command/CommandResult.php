<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

/**
 * The plugin's reply for one chat turn. `message` is Markdown ready to stream
 * back into the Synaplan chat.
 */
final readonly class CommandResult
{
    /**
     * @param array<string, mixed> $data optional structured payload for the UI/tests
     */
    public function __construct(
        public ResultType $type,
        public string $message,
        public array $data = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function info(string $message, array $data = []): self
    {
        return new self(ResultType::Info, $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function confirm(string $message, array $data = []): self
    {
        return new self(ResultType::NeedsConfirmation, $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(string $message, array $data = []): self
    {
        return new self(ResultType::Success, $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function error(string $message, array $data = []): self
    {
        return new self(ResultType::Error, $message, $data);
    }
}
