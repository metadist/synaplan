<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CircuitBreaker;
use App\Service\Exception\StreamCancelledException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class CircuitBreakerTest extends TestCase
{
    public function testProviderFailuresOpenTheCircuit(): void
    {
        $breaker = new CircuitBreaker(new ArrayAdapter(), new NullLogger(), failureThreshold: 2);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $breaker->execute(static fn () => throw new \RuntimeException('provider down'), 'ai_provider_test');
            } catch (\RuntimeException) {
                // expected — the failures are what opens the circuit
            }
        }

        $this->expectException(\App\AI\Exception\ProviderException::class);
        $breaker->execute(static fn () => 'never reached', 'ai_provider_test');
    }

    /**
     * Pressing Stop says nothing about provider health — counting cancels
     * would take the provider offline for everyone after a few clicks.
     */
    public function testUserCancellationDoesNotCountAsProviderFailure(): void
    {
        $breaker = new CircuitBreaker(new ArrayAdapter(), new NullLogger(), failureThreshold: 2);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            try {
                $breaker->execute(
                    static fn () => throw new StreamCancelledException('Stream cancelled by user'),
                    'ai_provider_test',
                );
                $this->fail('The cancellation must reach the caller unchanged');
            } catch (StreamCancelledException $e) {
                $this->assertSame('Stream cancelled by user', $e->getMessage());
            }
        }

        $this->assertSame('still closed', $breaker->execute(static fn () => 'still closed', 'ai_provider_test'));
    }
}
