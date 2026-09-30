<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\Service\ModelDiscovery\ModelDiscoveryStateStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryStateStoreTest extends TestCase
{
    public function testClaimNotifySlotWinsOnInsertThenLosesOnSameSlot(): void
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

        $this->assertTrue($store->claimNotifySlot('2026-09-24 09'));
        $this->assertFalse($store->claimNotifySlot('2026-09-24 09'));
        $this->assertSame('2026-09-24 09', $calls[1]['params']['slot']);
        $this->assertSame(ModelDiscoveryStateStore::SETTING_NOTIFY_CLAIM, $calls[1]['params']['setting']);
    }

    public function testClaimNotifySlotWinsOnConditionalUpdate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with($this->stringContains('UPDATE BCONFIG'), $this->anything())
            ->willReturn(1);

        $store = new ModelDiscoveryStateStore($connection);
        $this->assertTrue($store->claimNotifySlot('2026-09-25 10'));
    }

    public function testFailureClaimUsesItsOwnSetting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with(
                $this->stringContains('AND BVALUE <> :slot'),
                $this->callback(static fn (array $params): bool => '2026-09-25' === ($params['slot'] ?? null)
                    && ModelDiscoveryStateStore::SETTING_FAILURE_CLAIM === ($params['setting'] ?? null)),
            )
            ->willReturn(1);

        $this->assertTrue((new ModelDiscoveryStateStore($connection))->claimFailureDay('2026-09-25'));
    }

    public function testReminderSentOnRoundTrip(): void
    {
        $saved = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(false, '', '2026-09-28');
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []) use (&$saved): int {
                if (ModelDiscoveryStateStore::SETTING_REMINDER_SENT === ($params['setting'] ?? null)) {
                    $saved = $params['value'] ?? null;
                }

                return 1;
            },
        );

        $store = new ModelDiscoveryStateStore($connection);
        $this->assertNull($store->loadReminderSentOn());
        $this->assertNull($store->loadReminderSentOn());
        $this->assertSame('2026-09-28', $store->loadReminderSentOn());

        $store->saveReminderSentOn('2026-09-28');
        $this->assertSame('2026-09-28', $saved);
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

    public function testReleaseNotifySlotOnlyMatchesOwnSlot(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('UPDATE BCONFIG SET BVALUE'),
                    $this->stringContains('AND BVALUE = :slot'),
                ),
                $this->callback(static function (array $params): bool {
                    return '2026-09-24 09' === ($params['slot'] ?? null)
                        && ModelDiscoveryStateStore::CONFIG_GROUP === ($params['group'] ?? null)
                        && ModelDiscoveryStateStore::SETTING_NOTIFY_CLAIM === ($params['setting'] ?? null);
                }),
            )
            ->willReturn(1);

        (new ModelDiscoveryStateStore($connection))->releaseNotifySlot('2026-09-24 09');
    }
}
