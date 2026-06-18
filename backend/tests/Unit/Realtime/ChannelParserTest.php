<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Realtime\Channel\ChannelParser;
use App\Realtime\Channel\SystemBroadcastChannel;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Channel\WidgetOperatorsChannel;
use App\Realtime\Channel\WidgetSessionChannel;
use App\Realtime\Channel\WidgetTypingChannel;
use App\Realtime\Exception\InvalidChannelException;
use PHPUnit\Framework\TestCase;

final class ChannelParserTest extends TestCase
{
    private ChannelParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ChannelParser();
    }

    public function testParsesWidgetSessionChannel(): void
    {
        $channel = $this->parser->parse('widget:session.wdg_abc.sid_123');

        $this->assertInstanceOf(WidgetSessionChannel::class, $channel);
        $this->assertSame('widget', $channel->namespace());
        $this->assertSame('widget:session.wdg_abc.sid_123', $channel->name());
        $this->assertSame('wdg_abc', $channel->widgetId);
        $this->assertSame('sid_123', $channel->sessionId);
    }

    public function testParsesWidgetOperatorsChannel(): void
    {
        $channel = $this->parser->parse('widget:operators.wdg_x');

        $this->assertInstanceOf(WidgetOperatorsChannel::class, $channel);
        $this->assertSame('wdg_x', $channel->widgetId);
    }

    public function testParsesUserChannel(): void
    {
        $channel = $this->parser->parse('user:42');

        $this->assertInstanceOf(UserChannel::class, $channel);
        $this->assertSame(42, $channel->userId);
    }

    public function testParsesSystemBroadcastChannel(): void
    {
        $channel = $this->parser->parse('system:maintenance');

        $this->assertInstanceOf(SystemBroadcastChannel::class, $channel);
        $this->assertSame('maintenance', $channel->topic);
    }

    public function testRejectsEmptyChannel(): void
    {
        $this->expectException(InvalidChannelException::class);
        $this->parser->parse('');
    }

    public function testRejectsMissingNamespace(): void
    {
        $this->expectException(InvalidChannelException::class);
        $this->parser->parse('no-colon-here');
    }

    public function testRejectsUnknownNamespace(): void
    {
        $this->expectException(InvalidChannelException::class);
        $this->parser->parse('mystery:foo');
    }

    public function testRejectsMalformedWidgetSession(): void
    {
        $this->expectException(InvalidChannelException::class);
        $this->parser->parse('widget:session.justwidget');
    }

    public function testParsesWidgetTypingChannel(): void
    {
        $channel = $this->parser->parse('widgettyping:wdg_abc.sid_123');

        $this->assertInstanceOf(WidgetTypingChannel::class, $channel);
        $this->assertSame('widgettyping', $channel->namespace());
        $this->assertSame('widgettyping:wdg_abc.sid_123', $channel->name());
        $this->assertSame('wdg_abc', $channel->widgetId);
        $this->assertSame('sid_123', $channel->sessionId);
    }

    public function testRejectsMalformedWidgetTypingMissingSession(): void
    {
        $this->expectException(InvalidChannelException::class);
        $this->parser->parse('widgettyping:wdg_only');
    }

    public function testRejectsMalformedWidgetTypingEmptySegment(): void
    {
        $this->expectException(InvalidChannelException::class);
        $this->parser->parse('widgettyping:wdg_abc.');
    }

    public function testRejectsNonNumericUserId(): void
    {
        $this->expectException(InvalidChannelException::class);
        $this->parser->parse('user:abc');
    }
}
