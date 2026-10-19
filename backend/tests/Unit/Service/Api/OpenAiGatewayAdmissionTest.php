<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Api;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Security\FreeApiGatewayGuard;
use App\Service\Api\OpenAiGatewayAdmission;
use App\Service\Api\OpenAiGatewayException;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class OpenAiGatewayAdmissionTest extends TestCase
{
    private function limits(bool $rateAllowed, bool $budgetAllowed, bool $expectRateCheck = true): RateLimitService
    {
        $limits = $this->createMock(RateLimitService::class);
        $limits->expects($expectRateCheck ? self::once() : self::never())
            ->method('checkLimit')
            ->with(self::isInstanceOf(User::class), 'MESSAGES')
            ->willReturn(['allowed' => $rateAllowed]);
        $limits->method('checkCostBudget')->willReturn(['allowed' => $budgetAllowed]);

        return $limits;
    }

    private function gatedDemoRequest(): Request
    {
        $request = Request::create('/v1/chat/completions', 'POST');
        $request->attributes->set('api_key', (new ApiKey())
            ->setOwnerId(7)
            ->setKey('sk_test')
            ->setStatus('active')
            ->setName('demo')
            ->setScopes(['freeapi']));
        $request->attributes->set(FreeApiGatewayGuard::GATED_ATTRIBUTE, true);

        return $request;
    }

    private function refusal(RateLimitService $limits, Request $request): ?OpenAiGatewayException
    {
        try {
            OpenAiGatewayAdmission::admit($limits, $request, new User(), 'MESSAGES');
        } catch (OpenAiGatewayException $e) {
            return $e;
        }

        return null;
    }

    public function testARequestWithinLimitAndBudgetIsAdmitted(): void
    {
        self::assertNull($this->refusal($this->limits(true, true), Request::create('/v1/chat/completions', 'POST')));
    }

    public function testTheRateLimitRefusesWith429(): void
    {
        $refusal = $this->refusal($this->limits(false, true), Request::create('/v1/chat/completions', 'POST'));

        self::assertNotNull($refusal);
        self::assertSame(429, $refusal->httpStatus);
        self::assertSame('rate_limit_exceeded', $refusal->errorCode);
    }

    public function testAnExhaustedBudgetRefusesWithInsufficientQuota(): void
    {
        $refusal = $this->refusal($this->limits(true, false), Request::create('/v1/chat/completions', 'POST'));

        self::assertNotNull($refusal);
        self::assertSame(429, $refusal->httpStatus);
        self::assertSame('insufficient_quota', $refusal->errorCode);
    }

    public function testTheDemoGateSkipsTheRateLimitButNeverTheBudget(): void
    {
        self::assertNull($this->refusal($this->limits(false, true, false), $this->gatedDemoRequest()));

        $refusal = $this->refusal($this->limits(false, false, false), $this->gatedDemoRequest());
        self::assertNotNull($refusal);
        self::assertSame('insufficient_quota', $refusal->errorCode);
    }

    public function testTheGatedAttributeAloneDoesNotSkipTheRateLimitForOtherKeys(): void
    {
        $request = Request::create('/v1/chat/completions', 'POST');
        $request->attributes->set(FreeApiGatewayGuard::GATED_ATTRIBUTE, true);

        self::assertNotNull($this->refusal($this->limits(false, true), $request));
    }

    public function testTheRefusalRendersTheOpenAiErrorShape(): void
    {
        $body = json_decode((string) OpenAiGatewayException::budgetExhausted()->toResponse()->getContent(), true);

        self::assertSame(['message', 'type', 'param', 'code'], array_keys($body['error']));
        self::assertSame('insufficient_quota', $body['error']['type']);
        self::assertNull($body['error']['param']);
    }
}
