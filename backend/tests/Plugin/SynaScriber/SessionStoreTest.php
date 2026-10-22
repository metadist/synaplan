<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use Plugin\SynaScriber\Service\SessionStore;

final class SessionStoreTest extends PluginTestCase
{
    private function store(): SessionStore
    {
        return new SessionStore($this->pluginData(), $this->settings());
    }

    public function testMixedCaseRefIsStoredUnderACleanKeyAndFoundAgain(): void
    {
        $store = $this->store();
        $this->seedSession($store);

        $keys = array_keys($this->rows['session']);
        self::assertCount(1, $keys);
        self::assertMatchesRegularExpression('/^s_[a-f0-9]{40}$/', $keys[0]);
        self::assertSame(self::REF, $store->find(self::REF)['ref'] ?? null);
        self::assertNull($store->find('Zz3dEf9hJkLmN0pQrStUvW'));
    }

    public function testSessionsFromVersion02StayReadable(): void
    {
        $store = $this->store();
        $this->rows['session']['0123456789abcdef'] = ['ref' => '0123456789abcdef', 'state' => 'saved'];

        self::assertSame('saved', $store->find('0123456789abcdef')['state'] ?? null);
    }

    public function testARetriedWindowReplacesItsSegment(): void
    {
        $store = $this->store();
        $segment = ['at' => 1_700_000_000_000, 'speaker' => 'Ep-1', 'seq' => 3];

        $store->addSegment(self::REF, $segment + ['text' => 'erster Versuch']);
        $store->addSegment(self::REF, $segment + ['text' => 'zweiter Versuch']);
        $store->addSegment(self::REF, ['at' => 1_700_000_001_000, 'speaker' => 'ep2', 'seq' => 1, 'text' => 'andere Person']);

        self::assertSame('zweiter Versuch', $store->findSegment(self::REF, $segment)['text'] ?? null);
        self::assertSame(['zweiter Versuch', 'andere Person'], array_column($store->segments(self::REF), 'text'));
        foreach (array_keys($this->rows) as $type) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $type);
        }
    }

    public function testDeletingSegmentsLeavesTheSession(): void
    {
        $store = $this->store();
        $this->seedSession($store);
        $store->addSegment(self::REF, ['at' => 1, 'speaker' => 'ep1', 'seq' => 1, 'text' => 'x']);

        $store->deleteSegments(self::REF);

        self::assertSame([], $store->segments(self::REF));
        self::assertNotNull($store->find(self::REF));
    }
}
