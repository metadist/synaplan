<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Observability;

use App\AI\Catalog\ProviderCatalog;
use App\Observability\RequestTimelineStore;
use App\Observability\ServerIdentity;
use App\Service\Egress\EgressConfig;
use App\Service\Egress\EgressDestinationStanding;
use App\Service\Egress\EgressMode;
use App\Service\Infrastructure\RedisService;
use App\Service\Observability\CapacityOutboundReport;
use App\Service\Observability\CapacityTimelineService;
use App\Service\Observability\CapacityToolsReport;
use App\Service\Observability\CapacityUnavailableException;
use PHPUnit\Framework\TestCase;

/**
 * The Requests, Tools and Outbound reports behind Admin › Capacity (AU33):
 * counts and timings over the timeline ring, never content.
 */
final class CapacityTimelineReportsTest extends TestCase
{
    protected function tearDown(): void
    {
        EgressConfig::setModeOverride(null);
        EgressConfig::setAllowOverride(null);
    }

    public function testToolsAreCountedPerSourceAndKindWithWaitingCallsApart(): void
    {
        $store = $this->store([
            $this->record('a', tools: [
                ['source' => 'builtin', 'kind' => 'read', 'outcome' => 'done', 'duration_ms' => 120],
                ['source' => 'mcp', 'kind' => 'write', 'outcome' => 'needs_approval', 'duration_ms' => 5],
            ]),
            $this->record('b', tools: [
                ['source' => 'builtin', 'kind' => 'read', 'outcome' => 'timed_out', 'duration_ms' => 30000],
                ['source' => 'builtin', 'kind' => 'read', 'outcome' => 'refused', 'duration_ms' => 1],
            ]),
        ]);

        $tools = (new CapacityToolsReport($store, new ServerIdentity('web1')))->report()['tools'];

        self::assertSame(
            [
                ['source' => 'builtin', 'kind' => 'read', 'calls' => 3, 'done' => 1, 'refused' => 1, 'waiting' => 0, 'failed' => 1],
                ['source' => 'mcp', 'kind' => 'write', 'calls' => 1, 'done' => 0, 'refused' => 0, 'waiting' => 1, 'failed' => 0],
            ],
            array_map(static fn (array $row): array => array_diff_key($row, ['p95_ms' => true]), $tools),
        );
        self::assertGreaterThanOrEqual(120, $tools[0]['p95_ms']);
    }

    public function testOutboundListsNotListedDestinationsFirstWithTheRuleAllowWouldWrite(): void
    {
        EgressConfig::setModeOverride(EgressMode::Report);
        EgressConfig::setAllowOverride(['listed.example.com']);
        $store = $this->store([
            $this->record('a', egress: [
                ['destination' => 'api.openai.com:443', 'status_class' => '2xx', 'duration_ms' => 800],
                ['destination' => 'api.openai.com:443', 'status_class' => '5xx', 'duration_ms' => 900],
                ['destination' => 'listed.example.com:443', 'status_class' => '2xx', 'duration_ms' => 40],
            ]),
            $this->record('b', egress: [
                ['destination' => 'crm.example.com:443', 'status_class' => '2xx', 'duration_ms' => 60],
                ['destination' => 'typed', 'status_class' => 'blocked', 'duration_ms' => 0],
            ]),
        ]);

        $report = $this->outbound($store)->report();

        self::assertSame('report', $report['mode']);
        $byDestination = array_column($report['destinations'], null, 'destination');
        self::assertSame('crm.example.com:443', $report['destinations'][0]['destination']);
        self::assertSame(['not_listed', null, 'crm.example.com'], [
            $byDestination['crm.example.com:443']['state'],
            $byDestination['crm.example.com:443']['rule'],
            $byDestination['crm.example.com:443']['allow_rule'],
        ]);
        self::assertSame(['builtin', 2, 1], [
            $byDestination['api.openai.com:443']['state'],
            $byDestination['api.openai.com:443']['requests'],
            $byDestination['api.openai.com:443']['failures'],
        ]);
        self::assertSame(['listed', 'listed.example.com'], [
            $byDestination['listed.example.com:443']['state'],
            $byDestination['listed.example.com:443']['rule'],
        ]);
        self::assertSame(['typed', 1, null], [
            $byDestination['typed']['state'],
            $byDestination['typed']['refused'],
            $byDestination['typed']['allow_rule'],
        ]);
    }

    public function testRequestsReportTheWindowAndTheWaitForPhpWhenMeasured(): void
    {
        $now = time();
        $store = $this->store([
            $this->record('new', ts: $now - 30, extra: ['wait_ms' => 250]),
            $this->record('old', ts: $now - 600),
            $this->record('api', ts: $now - 60, routeClass: 'openai_api'),
        ]);

        $report = (new CapacityTimelineService($store, new ServerIdentity('web1')))->requestsReport();

        self::assertGreaterThanOrEqual(600, $report['window_seconds']);
        self::assertLessThan(660, $report['window_seconds']);
        $byRoute = array_column($report['route_classes'], null, 'route_class');
        self::assertGreaterThanOrEqual(250, $byRoute['chat_stream']['wait_p95_ms']);
        self::assertNull($byRoute['openai_api']['wait_p95_ms']);
    }

    public function testEveryReportSaysSoWhenRedisDoesNotAnswer(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('isAvailable')->willReturn(false);
        $store = new RequestTimelineStore($redis);

        foreach ([
            fn () => (new CapacityToolsReport($store, new ServerIdentity('web1')))->report(),
            fn () => $this->outbound($store)->report(),
        ] as $read) {
            try {
                $read();
                self::fail('A report without Redis must say so.');
            } catch (CapacityUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function outbound(RequestTimelineStore $store): CapacityOutboundReport
    {
        $config = new EgressConfig();

        return new CapacityOutboundReport(
            $store,
            new ServerIdentity('web1'),
            $config,
            new EgressDestinationStanding($config, ProviderCatalog::builtIn()),
        );
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $egress
     * @param array<string, mixed>       $extra
     *
     * @return array<string, mixed>
     */
    private function record(
        string $id,
        array $tools = [],
        array $egress = [],
        ?int $ts = null,
        string $routeClass = 'chat_stream',
        array $extra = [],
    ): array {
        return [
            'id' => $id,
            'ts' => $ts ?? time(),
            'server' => 'web1',
            'route_class' => $routeClass,
            'status_class' => '2xx',
            'total_ms' => 1200,
            'php_ms' => 1200,
            'db_ms' => 10,
            'db_queries' => 2,
            'ai' => [],
            'tools' => $tools,
            'egress' => $egress,
            'user_id' => null,
        ] + $extra;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function store(array $records): RequestTimelineStore
    {
        $encoded = array_map(static fn (array $r): string => json_encode($r, \JSON_THROW_ON_ERROR), $records);
        $redis = $this->createStub(RedisService::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('zRevRangeByScore')->willReturn($encoded);

        return new RequestTimelineStore($redis);
    }
}
