<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Iam;

use App\Message\DeleteComputeWorkspaceCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * The folder cleanup of a deleted account is the only reference to that
 * folder, so it must travel on a transport that retries it, never on the
 * synchronous bus.
 */
final class ComputeWorkspaceCleanupRoutingTest extends KernelTestCase
{
    private const TRANSPORT = 'async_index';
    private const MAX_RETRIES = 5;

    public function testTheCleanupIsQueuedOnTheRetryingIndexTransport(): void
    {
        self::bootKernel();
        $senders = static::getContainer()->get('messenger.senders_locator');
        self::assertInstanceOf(SendersLocatorInterface::class, $senders);

        $routed = array_keys(iterator_to_array($senders->getSenders(new Envelope(new DeleteComputeWorkspaceCommand('01JLIVE')))));

        self::assertSame([self::TRANSPORT], $routed);
    }

    public function testTheTransportRetriesBeforeTheMessageIsParkedInFailed(): void
    {
        self::bootKernel();
        $strategy = static::getContainer()->get('messenger.retry_strategy_locator')->get(self::TRANSPORT);
        self::assertInstanceOf(RetryStrategyInterface::class, $strategy);
        $envelope = new Envelope(new DeleteComputeWorkspaceCommand('01JLIVE'));

        self::assertTrue($strategy->isRetryable($envelope->with(new RedeliveryStamp(self::MAX_RETRIES - 1))));
        self::assertFalse($strategy->isRetryable($envelope->with(new RedeliveryStamp(self::MAX_RETRIES))));
    }
}
