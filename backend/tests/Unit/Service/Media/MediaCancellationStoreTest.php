<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\MediaCancellationStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for {@see MediaCancellationStore} — the cross-process Stop-button
 * flag store. Uses an in-memory PSR-6 adapter (the real one is the shared app
 * cache).
 */
class MediaCancellationStoreTest extends TestCase
{
    public function testNothingIsCancelledByDefault(): void
    {
        $store = new MediaCancellationStore(new ArrayAdapter());

        self::assertFalse($store->isCancelled('t1'));
        self::assertFalse($store->isCancelled('t1', 'n1'));
    }

    public function testTrackScopeCancelsEveryNode(): void
    {
        $store = new MediaCancellationStore(new ArrayAdapter());

        $store->requestCancel('t1');

        self::assertTrue($store->isCancelled('t1'));
        self::assertTrue($store->isCancelled('t1', 'n1'));
        self::assertTrue($store->isCancelled('t1', 'n2'));
    }

    public function testNodeScopeCancelsOnlyThatNode(): void
    {
        $store = new MediaCancellationStore(new ArrayAdapter());

        $store->requestCancel('t1', 'n2');

        self::assertTrue($store->isCancelled('t1', 'n2'));
        self::assertFalse($store->isCancelled('t1', 'n3'));
        // The whole-track scope must stay clean — siblings keep running.
        self::assertFalse($store->isCancelled('t1'));
    }

    public function testIsolatedAcrossTracks(): void
    {
        $store = new MediaCancellationStore(new ArrayAdapter());

        $store->requestCancel('t1');

        self::assertFalse($store->isCancelled('t2'));
        self::assertFalse($store->isCancelled('t2', 'n1'));
    }

    public function testBlankIdentifiersAreNoOps(): void
    {
        $store = new MediaCancellationStore(new ArrayAdapter());

        $store->requestCancel('');

        self::assertFalse($store->isCancelled(''));
        self::assertFalse($store->isCancelled('t1'));
    }
}
