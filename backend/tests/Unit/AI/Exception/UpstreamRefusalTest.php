<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Exception;

use App\AI\Exception\ProviderException;
use App\AI\Exception\UpstreamRefusal;
use App\AI\Health\FailureClassifier;
use App\AI\Health\FailureKind;
use App\Service\Infrastructure\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A Demo refusal ("today's messages are used up") comes from a healthy
 * gateway. It must reach the user as itself, never open the shared
 * OpenAI-compatible circuit and never count against the model.
 */
final class UpstreamRefusalTest extends TestCase
{
    public function testTheCodeIsFoundOnTheProviderExceptionOrItsCause(): void
    {
        $direct = new ProviderException('limit', 'openaicompatible', ['error_code' => 'freeapi_limit_reached'], 429);
        $wrapped = new \RuntimeException('outer', 0, $direct);

        $this->assertSame('freeapi_limit_reached', UpstreamRefusal::codeOf($direct));
        $this->assertSame('freeapi_limit_reached', UpstreamRefusal::codeOf($wrapped));
    }

    public function testOrdinaryFailuresAreNotRefusals(): void
    {
        $this->assertFalse(UpstreamRefusal::isRefusal(new ProviderException('rate limit', 'openaicompatible', ['error_code' => 'rate_limit_exceeded'], 429)));
        $this->assertFalse(UpstreamRefusal::isRefusal(new ProviderException('down', 'openaicompatible', null, 503)));
        $this->assertFalse(UpstreamRefusal::isRefusal(new \RuntimeException('freeapi_limit_reached')), 'the message alone proves nothing');
    }

    public function testRefusalsNeverOpenTheCircuit(): void
    {
        $breaker = new CircuitBreaker(new ArrayAdapter(), new NullLogger(), failureThreshold: 2);
        $refusal = new ProviderException('The Synaplan Demo is fully used for today.', 'openaicompatible', ['error_code' => 'freeapi_full_today'], 429);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            try {
                $breaker->execute(static fn () => throw $refusal, 'ai_provider_openaicompatible');
                $this->fail('the refusal must reach the caller');
            } catch (ProviderException $e) {
                $this->assertSame($refusal, $e);
            }
        }

        $this->assertSame('still closed', $breaker->execute(static fn () => 'still closed', 'ai_provider_openaicompatible'));
    }

    public function testRefusalsDoNotCountAgainstTheModel(): void
    {
        $kind = (new FailureClassifier())->classify(
            new ProviderException('Your 14-day Demo has ended.', 'openaicompatible', ['error_code' => 'freeapi_expired'], 403),
        );

        $this->assertSame(FailureKind::UserError, $kind);
        $this->assertFalse($kind->countsAgainstModel());
        $this->assertFalse($kind->justifiesAutoDisable());
    }
}
