<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Usage;

use App\Entity\User;
use App\Service\RateLimitService;
use App\Service\Usage\DemoCallContext;
use App\Service\Usage\LegacyUsageWriter;
use App\Service\Usage\RecordedUsage;
use App\Service\Usage\UsageActivityRow;
use App\Service\Usage\UsageOutcome;
use PHPUnit\Framework\TestCase;

final class DemoCallStatisticsTest extends TestCase
{
    private const INSTALL = '11111111-2222-4333-8444-555555555555';

    protected function tearDown(): void
    {
        DemoCallContext::clear();
        LegacyUsageWriter::bind(null);
    }

    public function testRefusalKeepsTheCodeAndDropsThePrompt(): void
    {
        $row = UsageOutcome::refusal(7, 1_700_000_000, 'freeapi_edge_required', 'tell me a secret', self::INSTALL, 'free-chat', 403, '/v1/chat/completions');

        self::assertSame(UsageOutcome::ACTION, $row['action']);
        self::assertSame('error', $row['status']);
        self::assertSame('freeapi_edge_required', $row['error']);
        self::assertSame('free-chat', $row['model']);
        self::assertSame('0.000000', $row['cost']);
        $meta = json_decode((string) $row['metadata'], true);
        self::assertSame(self::INSTALL, $meta['install_id']);
        self::assertSame('free-chat', $meta['alias']);
        self::assertSame(403, $meta['http']);
        self::assertArrayNotHasKey('prompt', $meta);
        self::assertStringNotContainsString('secret', (string) $row['metadata']);
    }

    public function testUnknownRefusalCodeIsReplaced(): void
    {
        $row = UsageOutcome::refusal(7, 1, '<script>', '', 'not-a-uuid', '', 99, 'https://evil');

        self::assertSame('freeapi_refused', $row['error']);
        self::assertSame('demo', $row['model']);
        $meta = json_decode((string) $row['metadata'], true);
        self::assertArrayNotHasKey('install_id', $meta);
        self::assertArrayNotHasKey('http', $meta);
    }

    public function testIssuedKeyRecordsTheInstall(): void
    {
        $row = UsageOutcome::issued(7, 1, self::INSTALL);

        self::assertSame('success', $row['status']);
        self::assertSame('', $row['error']);
        self::assertSame('key', $row['model']);
        $meta = json_decode((string) $row['metadata'], true);
        self::assertSame(self::INSTALL, $meta['install_id']);
        self::assertSame('/v1/free/keys', $meta['path']);
    }

    public function testOpenCallStampsTheUsageRow(): void
    {
        DemoCallContext::attach(self::INSTALL, 'free-chat');
        $seen = [];
        $limits = $this->createMock(RateLimitService::class);
        $limits->expects($this->once())->method('recordUsage')->willReturnCallback(
            function (User $user, string $action, array $metadata) use (&$seen): RecordedUsage {
                $seen = [$action, $metadata];

                return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
            }
        );

        LegacyUsageWriter::record($limits, $this->createStub(User::class), 'MESSAGES', ['provider' => 'cerebras', 'model' => 'gpt-oss-120b']);

        self::assertSame('MESSAGES', $seen[0]);
        self::assertSame(self::INSTALL, $seen[1]['install_id']);
        self::assertSame('free-chat', $seen[1]['alias']);
        self::assertSame('gpt-oss-120b', $seen[1]['model']);
    }

    public function testActivityRowExposesTheRefusal(): void
    {
        $item = UsageActivityRow::from([
            'timestamp' => 1_700_000_000,
            'action' => 'DEMO',
            'source' => 'synaplan',
            'model' => 'free-chat',
            'tokens' => 0,
            'cost' => 0,
            'latency' => 0,
            'status' => 'error',
            'error' => 'freeapi_edge_required',
            'metadata' => json_encode([
                'source' => 'OPENAI_API',
                'install_id' => self::INSTALL,
                'alias' => 'free-chat',
                'path' => '/v1/chat/completions',
                'http' => 403,
            ]),
        ], 'OPENAI_API', null, null);

        self::assertSame('error', $item['status']);
        self::assertSame('freeapi_edge_required', $item['error']);
        self::assertSame(self::INSTALL, $item['install_id']);
        self::assertSame('free-chat', $item['alias']);
        self::assertSame(403, $item['http']);
        self::assertSame('/v1/chat/completions', $item['path']);
    }
}
