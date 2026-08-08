<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\ConfigRepository;
use App\Service\RateLimitService;
use App\Service\UsageStatsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the private `channelFromMetadata` helper that maps a
 * BUSELOG.BMETADATA JSON blob to the communication channel shown on the
 * Statistics page (WEB, WIDGET, MESSAGES_API for Claude Code, OPENAI_API, …).
 *
 * Tested via reflection for the same reason as
 * {@see UsageStatsServiceDeriveSubscriptionStatusTest}: it is pure logic and
 * mocking the DB collaborators would be noise.
 */
final class UsageStatsServiceChannelTest extends TestCase
{
    private UsageStatsService $service;

    protected function setUp(): void
    {
        $this->service = new UsageStatsService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ConfigRepository::class),
            $this->createMock(RateLimitService::class),
            new NullLogger(),
        );
    }

    #[DataProvider('metadataCases')]
    public function testChannelFromMetadata(string $expected, ?string $metadataJson): void
    {
        $method = new \ReflectionMethod($this->service, 'channelFromMetadata');

        $this->assertSame($expected, $method->invoke($this->service, $metadataJson));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function metadataCases(): iterable
    {
        // Legacy rows written before the source field existed.
        yield 'null metadata → WEB' => ['WEB', null];
        yield 'empty string → WEB' => ['WEB', ''];
        yield 'malformed JSON → WEB' => ['WEB', '{not json'];
        yield 'JSON scalar → WEB' => ['WEB', '"just a string"'];
        yield 'no source key → WEB' => ['WEB', '{"model":"gpt-4o"}'];
        yield 'empty source → WEB' => ['WEB', '{"source":""}'];
        yield 'non-string source → WEB' => ['WEB', '{"source":123}'];

        // Real channels recorded by the various entry points.
        yield 'web' => ['WEB', '{"source":"WEB"}'];
        yield 'lowercase source is normalised' => ['WHATSAPP', '{"source":"whatsapp"}'];
        yield 'widget' => ['WIDGET', '{"source":"WIDGET"}'];
        yield 'Anthropic gateway (Claude Code)' => ['MESSAGES_API', '{"source":"MESSAGES_API"}'];
        yield 'OpenAI-compatible endpoint' => ['OPENAI_API', '{"source":"OPENAI_API"}'];
        yield 'session summarizer' => ['API_SUMMARY', '{"source":"API_SUMMARY"}'];
        yield 'MCP tool call' => ['MCP_TOOL', '{"source":"MCP_TOOL"}'];
    }
}
