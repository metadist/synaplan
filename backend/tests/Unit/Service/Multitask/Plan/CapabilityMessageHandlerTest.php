<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Plan;

use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Multitask\Plan\Capability;
use PHPUnit\Framework\TestCase;

/**
 * Every Capability case names its message-router handler, or explicitly names
 * none. A new enum case that is missing from {@see Capability::messageHandlerName()}
 * throws, and a case that is missing from this table fails the assertion.
 */
final class CapabilityMessageHandlerTest extends TestCase
{
    public function testEveryCapabilityNamesItsMessageHandler(): void
    {
        $expected = [
            'extract_text' => null,
            'chat' => 'chat',
            'summarize' => 'chat',
            'translate' => 'chat',
            'rag_query' => 'chat',
            'web_search' => null,
            'url_fetch' => null,
            'mcp_fetch' => null,
            'mcp_action' => null,
            'email_search' => null,
            'file_analysis' => 'file_analysis',
            'image_generation' => 'image_generation',
            'video_generation' => 'image_generation',
            'text2sound' => 'image_generation',
            'document_generation' => 'chat',
            'document_export' => null,
            'document_combine' => null,
            'calendar_event' => null,
            'email_me' => null,
            'save_to_folder' => null,
            'compose_reply' => null,
            'tool_call' => null,
            'outbound_webhook' => null,
            'condition' => null,
            'code_run' => null,
        ];

        $actual = [];
        foreach (Capability::cases() as $capability) {
            $actual[$capability->value] = $capability->messageHandlerName();
        }

        self::assertSame(Capability::values(), array_keys($expected));
        self::assertSame($expected, $actual);
    }

    public function testSystemCapabilityHandlersMatchTheCapabilityEnum(): void
    {
        foreach ((new SystemCapabilityRegistry())->intentToHandlerMap() as $intent => $handlerName) {
            self::assertSame($handlerName, Capability::from($intent)->messageHandlerName());
        }
    }
}
