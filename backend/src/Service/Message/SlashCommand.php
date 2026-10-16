<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * One parsed leading slash command from a user message.
 *
 * Shared by every channel that feeds {@see MessageProcessor} so Telegram,
 * WhatsApp, email and MCP all honour the same bare-command and rewrite rules.
 */
final readonly class SlashCommand
{
    /** Commands that must carry a non-empty argument or get a usage hint. */
    public const REQUIRES_ARGUMENT = ['pic', 'vid', 'tts', 'search', 'docs'];

    /**
     * Deterministic tool topics for commands that keep their slash form through
     * classification. `/search` and `/docs` are rewritten before classification
     * and are intentionally absent here.
     *
     * @var array<string, string>
     */
    public const TOOL_TOPICS = [
        'pic' => 'tools:pic',
        'vid' => 'tools:vid',
        'tts' => 'tools:tts',
        'lang' => 'tools:lang',
        'web' => 'tools:web',
        'list' => 'tools:list',
        'help' => 'synaplan',
    ];

    public function __construct(
        public string $name,
        public string $argument,
    ) {
    }

    public function requiresArgument(): bool
    {
        return in_array($this->name, self::REQUIRES_ARGUMENT, true);
    }

    public function isBare(): bool
    {
        return '' === $this->argument;
    }

    public function toolTopic(): ?string
    {
        return self::TOOL_TOPICS[$this->name] ?? null;
    }
}
