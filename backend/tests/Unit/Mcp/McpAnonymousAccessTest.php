<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\McpAnonymousAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class McpAnonymousAccessTest extends TestCase
{
    private McpAnonymousAccess $access;

    protected function setUp(): void
    {
        $this->access = new McpAnonymousAccess();
    }

    public function testAllowsDiscoveryMethods(): void
    {
        foreach (['initialize', 'notifications/initialized', 'ping', 'tools/list'] as $method) {
            self::assertTrue($this->access->allows($this->post($method)), $method);
        }
    }

    public function testRejectsToolCall(): void
    {
        self::assertFalse($this->access->allows($this->post('tools/call')));
    }

    public function testRejectsABatchThatContainsAToolCall(): void
    {
        $request = Request::create('/mcp', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call'],
        ]));

        self::assertFalse($this->access->allows($request));
    }

    public function testAllowsSessionDelete(): void
    {
        self::assertTrue($this->access->allows(Request::create('/mcp', 'DELETE')));
    }

    public function testRejectsInvalidJson(): void
    {
        $request = Request::create('/mcp', 'POST', content: '{');

        self::assertFalse($this->access->allows($request));
    }

    private function post(string $method): Request
    {
        return Request::create('/mcp', 'POST', content: (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
        ]));
    }
}
