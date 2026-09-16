<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\AI\Tool\CatalogToolUse;
use App\Model\ModelCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Dual-gate lock: capable chat families must carry `tool_use`; non-chat tags
 * must not, even when they share a provider id with a chat row.
 */
final class ModelCatalogToolUseConsistencyTest extends TestCase
{
    public function testCapableChatFamiliesCarryToolUse(): void
    {
        $missing = [];
        foreach (ModelCatalog::all() as $row) {
            if ('chat' !== ($row['tag'] ?? '')) {
                continue;
            }
            if (!CatalogToolUse::isCapableChatService((string) $row['service'])) {
                continue;
            }
            $features = $row['json']['features'] ?? [];
            if (!is_array($features) || !in_array('tool_use', $features, true)) {
                $missing[] = sprintf(
                    '%s:%s (BID %s)',
                    $row['service'],
                    $row['providerId'],
                    (string) $row['id'],
                );
            }
        }

        self::assertSame([], $missing, 'Capable chat models missing tool_use: '.implode(', ', $missing));
    }

    public function testNonChatTagsNeverCarryToolUse(): void
    {
        $leaks = [];
        foreach (ModelCatalog::all() as $row) {
            $tag = (string) ($row['tag'] ?? '');
            if (!in_array($tag, CatalogToolUse::NON_CHAT_TAGS, true)) {
                continue;
            }
            $features = $row['json']['features'] ?? [];
            if (is_array($features) && in_array('tool_use', $features, true)) {
                $leaks[] = sprintf(
                    '%s:%s:%s (BID %s)',
                    $row['service'],
                    $row['providerId'],
                    $tag,
                    (string) $row['id'],
                );
            }
        }

        self::assertSame([], $leaks, 'Non-chat rows must not carry tool_use: '.implode(', ', $leaks));
    }

    public function testCatalogToolUseLookupAgreesWithChatFlag(): void
    {
        self::assertTrue(CatalogToolUse::supports('test', 'test-model'));
        self::assertFalse(CatalogToolUse::supports('test', 'test-vectorize'));
        self::assertTrue(CatalogToolUse::supports('OpenAI', 'gpt-5.4'));
        self::assertFalse(CatalogToolUse::supports('OpenAI', 'whisper-1'));
    }
}
