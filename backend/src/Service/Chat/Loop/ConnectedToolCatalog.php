<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Tool\ToolDescriptor;

/**
 * The person's own tools (HTTP tools, MCP servers) behind one small tool.
 *
 * A connected server can bring dozens of tools with large schemas; declaring
 * them all on every call would make a plain question many times dearer. So a
 * run declares `connected_tools` instead: called without arguments it lists
 * the tools by name, called with `use` it opens the named ones, and from the
 * next model call on their full schemas are declared. Names and descriptions
 * come from the remote server and are untrusted; they reach the model as a
 * capped tool result, never as instructions in the system prompt.
 */
final class ConnectedToolCatalog
{
    public const NAME = 'connected_tools';

    /** At most this many tools are open in one run; each one adds its schema to every later call. */
    public const MAX_OPEN = 8;

    private const MAX_LISTED = 60;
    private const MAX_DESCRIPTION = 140;

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function declaration(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'The person\'s own connected tools (apps, MCP servers, HTTP tools). Call it without arguments to list them. Call it with `use` to open the ones you need, at most '.self::MAX_OPEN.'; then call them directly.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'use' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Names from the list to open.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Lists the tools, or opens the named ones into `$state->opened`.
     *
     * @param array<string, ToolDescriptor> $connected by wire name
     * @param array<string, mixed>          $args
     */
    public static function run(array $connected, ChatRunState $state, array $args): string
    {
        $use = $args['use'] ?? null;
        if (!\is_array($use) || [] === $use) {
            return self::listing($connected);
        }
        $opened = [];
        $unknown = [];
        foreach ($use as $name) {
            if (!\is_string($name) || !isset($connected[$name])) {
                $unknown[] = \is_string($name) ? mb_substr($name, 0, 64) : '?';
                continue;
            }
            if (!\in_array($name, $state->opened, true) && \count($state->opened) < self::MAX_OPEN) {
                $state->opened[] = $name;
            }
            if (\in_array($name, $state->opened, true)) {
                $opened[] = $name;
            }
        }
        $lines = [];
        if ([] !== $opened) {
            $lines[] = 'Open now: '.implode(', ', $opened).'. Call them directly.';
        }
        if ([] !== $unknown) {
            $lines[] = 'Not in the list: '.implode(', ', $unknown).'.';
        }
        if (\count($state->opened) >= self::MAX_OPEN && \count($opened) < \count($use) - \count($unknown)) {
            $lines[] = 'At most '.self::MAX_OPEN.' tools can be open in one run.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, ToolDescriptor> $connected
     */
    private static function listing(array $connected): string
    {
        if ([] === $connected) {
            return 'No connected tools.';
        }
        $lines = [];
        foreach (\array_slice($connected, 0, self::MAX_LISTED, true) as $wire => $descriptor) {
            $description = trim((string) preg_replace('/\s+/', ' ', $descriptor->description));
            $lines[] = '- '.$wire.('' !== $description ? ': '.mb_substr($description, 0, self::MAX_DESCRIPTION) : '');
        }
        if (\count($connected) > self::MAX_LISTED) {
            $lines[] = '('.(\count($connected) - self::MAX_LISTED).' more not listed)';
        }

        return implode("\n", $lines);
    }
}
