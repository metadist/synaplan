<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Tools\ServerToolReplay;
use PHPUnit\Framework\TestCase;

final class ServerToolReplayTest extends TestCase
{
    public function testUnpairedWebFetchUsesAreDroppedAndClientToolsKept(): void
    {
        $content = $this->loadBlocks('web_fetch_unpaired_assistant.json');

        self::assertSame(
            ['srvtoolu_01HUranyxaWc8P1UAGp6UAfb', 'srvtoolu_unpaired_fetch'],
            ServerToolReplay::unpairedUseIds($content),
        );

        $clean = ServerToolReplay::sanitizeAssistantContent($content);
        self::assertSame([], ServerToolReplay::unpairedUseIds($clean));
        self::assertCount(1, $clean);
        self::assertSame('tool_use', $clean[0]['type']);
        self::assertSame('web_search', $clean[0]['name']);
    }

    public function testPairedWebFetchIsKeptWithItsResult(): void
    {
        $content = $this->loadBlocks('web_fetch_paired_assistant.json');
        self::assertSame([], ServerToolReplay::unpairedUseIds($content));

        $clean = ServerToolReplay::sanitizeAssistantContent($content);
        self::assertCount(3, $clean);
        self::assertSame('server_tool_use', $clean[0]['type']);
        self::assertSame('srvtoolu_01HUranyxaWc8P1UAGp6UAfb', $clean[0]['id']);
        self::assertSame('web_fetch_tool_result', $clean[1]['type']);
        self::assertSame('srvtoolu_01HUranyxaWc8P1UAGp6UAfb', $clean[1]['tool_use_id']);
        self::assertSame('web_search', $clean[2]['name']);
    }

    public function testSanitizeMessagesDropsOrphanGenericToolResult(): void
    {
        $content = $this->loadBlocks('web_fetch_unpaired_assistant.json');
        $messages = [
            ['role' => 'user', 'content' => 'research Node LTS'],
            ['role' => 'assistant', 'content' => $content],
            ['role' => 'user', 'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'srvtoolu_01HUranyxaWc8P1UAGp6UAfb',
                'content' => 'wrong generic result',
            ]]],
        ];

        $clean = ServerToolReplay::sanitizeMessages($messages);
        self::assertSame([], ServerToolReplay::unpairedUseIds($clean[1]['content']));
        self::assertSame([], $clean[2]['content']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadBlocks(string $name): array
    {
        $raw = file_get_contents(dirname(__DIR__, 3).'/Fixtures/messages/'.$name);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        /* @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
