<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\Service\ModelDiscovery\ModelDiscoveryStateStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryStateStoreTest extends TestCase
{
    public function testClaimNotifyDayWinsOnInsertThenLosesOnSameDay(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []) use (&$calls): int {
                $calls[] = ['sql' => $sql, 'params' => $params];
                if (str_contains($sql, 'UPDATE BCONFIG')) {
                    return 0; // row absent
                }
                if (str_contains($sql, 'INSERT IGNORE')) {
                    // First claim inserts; second would be 0 — emulate via call count
                    $inserts = count(array_filter(
                        $calls,
                        static fn (array $c): bool => str_contains($c['sql'], 'INSERT IGNORE'),
                    ));

                    return 1 === $inserts ? 1 : 0;
                }

                return 0;
            },
        );

        $store = new ModelDiscoveryStateStore($connection);

        $this->assertTrue($store->claimNotifyDay('2026-09-24'));
        $this->assertFalse($store->claimNotifyDay('2026-09-24'));
    }

    public function testClaimNotifyDayWinsOnConditionalUpdate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with($this->stringContains('UPDATE BCONFIG'), $this->anything())
            ->willReturn(1);

        $store = new ModelDiscoveryStateStore($connection);
        $this->assertTrue($store->claimNotifyDay('2026-09-25'));
    }

    public function testLoadAndSaveProvidersRoundTripShape(): void
    {
        $saved = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []) use (&$saved): int {
                if (str_contains($sql, 'UPDATE BCONFIG') && isset($params['value'])) {
                    $saved = $params['value'];

                    return 1;
                }

                return 0;
            },
        );

        $store = new ModelDiscoveryStateStore($connection);
        $this->assertSame([], $store->loadProviders());

        $payload = [
            'openai' => [
                'baselineRecorded' => true,
                'baselineIds' => ['gpt-4o'],
                'seen' => ['gpt-4o' => '2026-09-24'],
            ],
        ];
        $store->saveProviders($payload);
        $this->assertNotNull($saved);
        $this->assertSame($payload, json_decode((string) $saved, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testLoadKeepsUpstreamIdsVerbatim(): void
    {
        $payload = [
            'groq' => [
                'baselineRecorded' => true,
                'baselineIds' => ['meta-llama/Llama-4-Scout'],
                'seen' => ['meta-llama/Llama-4-Scout' => '2026-09-24'],
            ],
        ];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(json_encode($payload, \JSON_THROW_ON_ERROR));

        // The service compares these against the provider's raw list, so a
        // case change here would re-report every baselined mixed-case id.
        $this->assertSame($payload, (new ModelDiscoveryStateStore($connection))->loadProviders());
    }
}
