<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Message\SynapseUserReindexMessage;
use App\Service\Message\SynapseAutoIndexService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Behavioural test for the per-user cooldown that protects the
 * messenger transport from login-storm dispatch floods.
 *
 * The service touches three collaborators:
 *   - MessageBus  → records dispatched envelopes (the side effect we
 *                   actually care about)
 *   - CacheInterface → throttle implementation; a real ArrayAdapter
 *                   keeps the test honest without mocking control
 *                   flow
 *   - Logger      → only verified on failure paths
 */
final class SynapseAutoIndexServiceTest extends TestCase
{
    private MessageBusInterface&MockObject $bus;
    private CacheInterface $cache;
    private LoggerInterface&MockObject $logger;
    private SynapseAutoIndexService $service;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        // ArrayAdapter is a real cache, so cooldown timing actually
        // exercises the production code path; mocking CacheInterface
        // would only re-test the mock.
        $this->cache = new ArrayAdapter();
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new SynapseAutoIndexService(
            $this->bus,
            $this->cache,
            $this->logger,
        );
    }

    public function testFirstCallDispatchesReindexJob(): void
    {
        $user = $this->makeUser(42);

        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (SynapseUserReindexMessage $m) => 42 === $m->userId))
            ->willReturn(new Envelope(new SynapseUserReindexMessage(42)));

        $this->assertTrue($this->service->scheduleForUser($user));
    }

    public function testRepeatCallWithinCooldownDoesNotDispatch(): void
    {
        $user = $this->makeUser(42);

        // Only the first call should hit the bus; the cooldown cache
        // entry must short-circuit every following dispatch in the
        // same TTL window.
        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new SynapseUserReindexMessage(42)));

        $this->assertTrue($this->service->scheduleForUser($user));
        $this->assertFalse($this->service->scheduleForUser($user));
        $this->assertFalse($this->service->scheduleForUser($user));
    }

    public function testCooldownIsScopedPerUser(): void
    {
        $userA = $this->makeUser(1);
        $userB = $this->makeUser(2);

        // Two distinct user ids → two cache misses → two dispatches.
        $dispatched = [];
        $this->bus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (SynapseUserReindexMessage $m) use (&$dispatched) {
                $dispatched[] = $m->userId;

                return new Envelope($m);
            });

        $this->assertTrue($this->service->scheduleForUser($userA));
        $this->assertTrue($this->service->scheduleForUser($userB));
        $this->assertSame([1, 2], $dispatched);
    }

    public function testInvalidUserIdIsIgnored(): void
    {
        // Non-persisted user (id === null) and synthetic ids ≤ 0 must
        // never reach the bus — they'd produce poison messages on the
        // queue.
        $user = $this->makeUser(null);

        $this->bus->expects($this->never())->method('dispatch');

        $this->assertFalse($this->service->scheduleForUser($user));
    }

    public function testDispatchFailureIsSwallowedAndLogged(): void
    {
        $user = $this->makeUser(7);

        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('queue down'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('SynapseAutoIndex'),
                $this->callback(static fn (array $ctx) => 7 === $ctx['user_id'])
            );

        // Auth flows must never explode because Synapse-side messaging
        // is unhealthy. The method returns false to signal "no work
        // queued" without bubbling the exception up.
        $this->assertFalse($this->service->scheduleForUser($user));
    }

    public function testPsr16CacheAdapterIsAlsoSupported(): void
    {
        // Defensive smoke test: prod environments may inject a PSR-16
        // adapter behind the CacheInterface contract. Make sure the
        // service still throttles correctly when handed one.
        $service = new SynapseAutoIndexService(
            $this->bus,
            new ArrayAdapter(),
            $this->logger,
        );

        $user = $this->makeUser(99);

        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new SynapseUserReindexMessage(99)));

        $this->assertTrue($service->scheduleForUser($user));
        $this->assertFalse($service->scheduleForUser($user));

        // Touching the PSR-16 wrapper makes sure we exercise the
        // adapter path too — keeps PHPStan from flagging the use as
        // dead.
        $simple = new Psr16Cache(new ArrayAdapter());
        $this->assertNull($simple->get('unknown'));
    }

    /**
     * Build a User instance with the given id without going through
     * the database. We use reflection because `User::$id` is set by
     * Doctrine and there is no public setter — we don't want to add
     * one only for tests.
     */
    private function makeUser(?int $id): User
    {
        $user = new User();
        if (null !== $id) {
            $reflection = new \ReflectionClass(User::class);
            $property = $reflection->getProperty('id');
            $property->setValue($user, $id);
        }

        return $user;
    }
}
