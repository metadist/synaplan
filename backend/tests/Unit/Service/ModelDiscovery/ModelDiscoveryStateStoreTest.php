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
                'baselineAnnounced' => false,
                'baselineIds' => ['gpt-4o'],
                'seen' => ['gpt-4o' => '2026-09-24'],
                'announced' => ['gpt-new' => '2026-09-25'],
                'failingSince' => null,
                'failureAnnounced' => false,
            ],
        ];
        $store->saveProviders($payload);
        $this->assertNotNull($saved);
        $this->assertSame($payload, json_decode((string) $saved, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testLoadDefaultsAnnouncedAndFailureFields(): void
    {
        $payload = [
            'openai' => [
                'baselineRecorded' => true,
                'baselineIds' => ['gpt-4o'],
                'seen' => ['gpt-4o' => '2026-09-24'],
            ],
        ];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(json_encode($payload, \JSON_THROW_ON_ERROR));

        $loaded = (new ModelDiscoveryStateStore($connection))->loadProviders();
        $this->assertFalse($loaded['openai']['baselineAnnounced']);
        $this->assertSame([], $loaded['openai']['announced']);
        $this->assertNull($loaded['openai']['failingSince']);
        $this->assertFalse($loaded['openai']['failureAnnounced']);
    }

    public function testLoadKeepsUpstreamIdsVerbatimIncludingAnnounced(): void
    {
        $payload = [
            'groq' => [
                'baselineRecorded' => true,
                'baselineAnnounced' => true,
                'baselineIds' => ['meta-llama/Llama-4-Scout'],
                'seen' => ['meta-llama/Llama-4-Scout' => '2026-09-24'],
                'announced' => ['meta-llama/Llama-4-Scout-New' => '2026-09-25'],
                'failingSince' => null,
                'failureAnnounced' => false,
            ],
        ];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(json_encode($payload, \JSON_THROW_ON_ERROR));

        $this->assertSame($payload, (new ModelDiscoveryStateStore($connection))->loadProviders());
    }

    public function testReleaseNotifyDayOnlyMatchesOwnDay(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE BCONFIG SET BVALUE'),
                    $this->stringContains('AND BVALUE = :day'),
                ),
                $this->callback(static function (array $params): bool {
                    return '2026-09-24' === ($params['day'] ?? null)
                        && ModelDiscoveryStateStore::CONFIG_GROUP === ($params['group'] ?? null)
                        && ModelDiscoveryStateStore::SETTING_NOTIFY_CLAIM === ($params['setting'] ?? null);
                }),
            )
            ->willReturn(1);

        (new ModelDiscoveryStateStore($connection))->releaseNotifyDay('2026-09-24');
    }
}
