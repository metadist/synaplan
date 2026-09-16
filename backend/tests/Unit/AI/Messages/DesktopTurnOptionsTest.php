<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\DesktopTurnOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DesktopTurnOptionsTest extends TestCase
{
    public function testMissingHeadersAreEmpty(): void
    {
        $opts = DesktopTurnOptions::fromRequest(Request::create('/v1/messages', 'POST'));
        $this->assertTrue($opts->isEmpty());
        $this->assertNull($opts->agentId);
        $this->assertNull($opts->ragGroupKey);
    }

    public function testReadsValidHeadersAndIgnoresJunk(): void
    {
        $request = Request::create('/v1/messages', 'POST');
        $request->headers->set('x-synaplan-agent-id', '12');
        $request->headers->set('x-synaplan-rag-group-key', 'DESKTOP:personal');
        $request->headers->set('x-synaplan-unknown', 'nope');

        $opts = DesktopTurnOptions::fromRequest($request);
        $this->assertSame(12, $opts->agentId);
        $this->assertSame('DESKTOP:personal', $opts->ragGroupKey);
        $this->assertFalse($opts->isEmpty());
    }

    public function testInvalidAgentIdIsIgnored(): void
    {
        $request = Request::create('/v1/messages', 'POST');
        $request->headers->set('x-synaplan-agent-id', 'abc');
        $request->headers->set('x-synaplan-rag-group-key', '  ');

        $opts = DesktopTurnOptions::fromRequest($request);
        $this->assertTrue($opts->isEmpty());
    }
}
