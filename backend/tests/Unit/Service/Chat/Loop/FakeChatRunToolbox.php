<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatRunScope;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunToolbox;
use App\Service\Chat\Loop\ChatToolResult;
use App\Service\Chat\Loop\ConnectedToolCatalog;

/**
 * Web search as a read, `custom:order` as a write that needs approval, and
 * the project's sources only while the chat is in a project.
 */
final class FakeChatRunToolbox implements ChatRunToolbox
{
    public const APPROVAL_ID = 77;

    /** @var list<array{name: string, args: array<string, mixed>, approvalId: ?int}> */
    public array $runs = [];

    /** @var array<string, string> what a read tool returns, by name, instead of the default */
    public array $results = [];

    /** @var list<list<string>> the opened tools each declaration was asked with */
    public array $openedSeen = [];

    public function declarations(ChatRunScope $scope, array $opened = []): array
    {
        $this->openedSeen[] = $opened;
        $names = ['web_search', 'custom:order'];
        if (null !== $scope->project) {
            $names[] = 'knowledge_search';
        }

        return array_map(static fn (string $name): array => ['type' => 'function', 'function' => ['name' => $name, 'description' => $name, 'parameters' => ['type' => 'object']]], $names);
    }

    public function run(ChatRunScope $scope, ChatRunState $state, array $call, ?int $approvalId = null): ChatToolResult
    {
        $this->runs[] = ['name' => $call['name'], 'args' => $call['args'], 'approvalId' => $approvalId];
        if (ConnectedToolCatalog::NAME === $call['name']) {
            $state->opened[] = 'custom_order';

            return ChatToolResult::text('Open now: custom_order.');
        }
        if ('custom:order' === $call['name'] && null === $approvalId) {
            return ChatToolResult::waitsFor(self::APPROVAL_ID);
        }
        if ('knowledge_search' === $call['name'] && null === $scope->project) {
            return ChatToolResult::text('This tool is not available in this chat.');
        }

        return ChatToolResult::text($this->results[$call['name']] ?? 'result of '.$call['name']);
    }

    public function isRead(string $name): bool
    {
        return \in_array($name, ['web_search', 'knowledge_search'], true);
    }
}
