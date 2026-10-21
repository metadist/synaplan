<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Health\ModelUnavailabilityWindow;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ModelUnavailabilityWindowTest extends TestCase
{
    private ArrayAdapter $cache;

    private ModelUnavailabilityWindow $window;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->window = new ModelUnavailabilityWindow($this->cache, new NullLogger());
    }

    public function testTwoFailuresDoNotOfferAChangeAndTheThirdDoes(): void
    {
        $this->window->record(7, 5_000);
        $this->window->record(7, 5_100);

        self::assertSame(2, $this->window->count(7, 5_100));
        self::assertFalse($this->window->offersChange(7, 5_100));

        $this->window->record(7, 5_200);

        self::assertSame(3, $this->window->count(7, 5_200));
        self::assertTrue($this->window->offersChange(7, 5_200));
    }

    public function testStampsOlderThanAnHourDropOut(): void
    {
        $this->window->record(7, 1_000);
        $this->window->record(7, 1_000);
        $this->window->record(7, 2_000);

        self::assertSame(3, $this->window->count(7, 2_000));
        // A stamp is kept for the whole hour, including the second it turns 3600 old.
        self::assertSame(1, $this->window->count(7, 1_000 + ModelUnavailabilityWindow::WINDOW_SECONDS + 1));
        self::assertSame(0, $this->window->count(7, 2_000 + ModelUnavailabilityWindow::WINDOW_SECONDS + 1));
        self::assertFalse($this->window->offersChange(7, 2_000 + ModelUnavailabilityWindow::WINDOW_SECONDS + 1));
    }

    public function testTheStoredListStopsAtThirtyNewestStamps(): void
    {
        for ($i = 0; $i < 40; ++$i) {
            $this->window->record(7, 10_000 + $i);
        }

        self::assertSame(30, $this->window->count(7, 10_039));
    }

    public function testABrokenCacheCountsAsZeroInsteadOfThrowing(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('redis down'));
        $window = new ModelUnavailabilityWindow($cache, new NullLogger());

        $window->record(7);
        self::assertSame(0, $window->count(7));

        $window->reset(7);
        self::assertSame(0, $window->count(7));
    }
}
