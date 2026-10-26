<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\PluginDataService;
use PHPUnit\Framework\TestCase;
use Plugin\SynaScriber\Service\SessionStore;
use Plugin\SynaScriber\Service\Settings;

abstract class PluginTestCase extends TestCase
{
    protected const REF = 'Ab3dEf9hJkLmN0pQrStUvW';
    protected const SECRET = 'transcriber-test-secret';

    /** @var array<string, array<string, array<string, mixed>>> data type => data key => row */
    protected array $rows = [];

    /** @var array<string, string> */
    protected array $config = [];

    /** @var list<int> user ids plugin_data was read or written under */
    protected array $dataOwners = [];

    /**
     * Settings over an in-memory config table; values not set fall back to
     * the plugin defaults.
     *
     * @param array<string, string> $values
     */
    protected function settings(array $values = []): Settings
    {
        $this->config = $values + ['transcriber_secret' => self::SECRET, 'enabled' => '1', 'stt_url' => 'http://stt.test'];
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(
            fn (int $owner, string $group, string $setting): ?string => $this->config[$setting] ?? null,
        );
        $repository->method('setValue')->willReturnCallback(
            function (int $owner, string $group, string $setting, string $value): Config {
                $this->config[$setting] = $value;

                return new Config();
            },
        );

        return new Settings($repository);
    }

    /**
     * plugin_data in memory, with the real service's quirk: type and key are
     * reduced to [a-z0-9_] on write but looked up as given.
     */
    protected function pluginData(): PluginDataService
    {
        $clean = static fn (string $key): string => preg_replace('/[^a-z0-9_]/', '', strtolower(trim($key))) ?? '';
        $data = $this->createStub(PluginDataService::class);
        $data->method('get')->willReturnCallback(
            function (int $user, string $plugin, string $type, string $key): ?array {
                $this->dataOwners[] = $user;

                return $this->rows[$type][$key] ?? null;
            },
        );
        $data->method('set')->willReturnCallback(
            function (int $user, string $plugin, string $type, string $key, array $row) use ($clean): void {
                $this->dataOwners[] = $user;
                $this->rows[$clean($type)][$clean($key)] = $row;
            },
        );
        $data->method('list')->willReturnCallback(
            fn (int $user, string $plugin, string $type): array => array_values($this->rows[$type] ?? []),
        );
        $data->method('listWithKeys')->willReturnCallback(
            fn (int $user, string $plugin, string $type): array => array_map(
                static fn (string|int $key, array $row): array => ['key' => (string) $key, 'data' => $row],
                array_keys($this->rows[$type] ?? []),
                array_values($this->rows[$type] ?? []),
            ),
        );
        $data->method('delete')->willReturnCallback(
            function (int $user, string $plugin, string $type, string $key): bool {
                $had = isset($this->rows[$type][$key]);
                unset($this->rows[$type][$key]);

                return $had;
            },
        );
        $data->method('deleteAllByType')->willReturnCallback(
            function (int $user, string $plugin, string $type): int {
                $count = count($this->rows[$type] ?? []);
                unset($this->rows[$type]);

                return $count;
            },
        );

        return $data;
    }

    /**
     * A running session as Start leaves it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function seedSession(SessionStore $store, array $overrides = []): array
    {
        $session = $overrides + [
            'ref' => self::REF,
            'room' => 'weekly',
            'starterId' => 2,
            'starterName' => 'Ada',
            'language' => 'de',
            'folder' => 'Meetings',
            'state' => 'running',
            'startedAt' => time(),
            'checkedAt' => time(),
            'meetingId' => 'meeting-1',
            'roster' => ['ep1' => 'Ada'],
            'attendees' => [],
            'files' => [],
        ];
        $store->save($session);

        return $session;
    }
}
