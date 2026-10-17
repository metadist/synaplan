<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\User;
use App\Service\Widget\WidgetDomainGuard;
use App\Service\Widget\WidgetRequestRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class WidgetDomainGuardTest extends TestCase
{
    public function testAnEmptyAllowlistLetsEverySiteEmbedTheWidget(): void
    {
        $this->guard()->check([], Request::create('/'), 7);
        $this->guard()->check(['allowedDomains' => []], $this->from('https://anywhere.test'), 7);

        $this->addToAssertionCount(1);
    }

    public function testARequestWithoutAHostIsRefused(): void
    {
        self::assertSame(
            [403, ['error' => 'Domain not allowed', 'reason' => 'missing_host']],
            $this->refusal(['allowedDomains' => ['shop.example.com']], Request::create('/')),
        );
    }

    public function testAHostOutsideTheAllowlistIsRefused(): void
    {
        self::assertSame(
            [403, ['error' => 'Domain not allowed', 'reason' => 'domain_not_whitelisted']],
            $this->refusal(['allowedDomains' => ['shop.example.com']], $this->from('https://elsewhere.test')),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: Request}>
     */
    public static function allowedHosts(): iterable
    {
        yield 'wildcard' => ['*.example.com', Request::create('/', 'POST', [], [], [], ['HTTP_ORIGIN' => 'https://shop.example.com'])];
        yield 'port' => ['example.com:8080', Request::create('/', 'POST', [], [], [], ['HTTP_ORIGIN' => 'http://example.com:8080'])];
        yield 'any port' => ['example.com', Request::create('/', 'POST', [], [], [], ['HTTP_REFERER' => 'https://example.com:8443/page'])];
        yield 'embed host header' => ['shop.example.com', Request::create('/', 'POST', [], [], [], ['HTTP_X_WIDGET_HOST' => 'https://SHOP.example.com/path?q=1'])];
    }

    #[DataProvider('allowedHosts')]
    public function testAnAllowedHostPasses(string $entry, Request $request): void
    {
        $this->guard()->check(['allowedDomains' => ['', $entry]], $request);

        $this->addToAssertionCount(1);
    }

    public function testTheOwnersTestAndInternalRequestsSkipTheCheck(): void
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(7);
        $config = ['allowedDomains' => ['shop.example.com']];

        foreach (['HTTP_X_WIDGET_TEST_MODE', 'HTTP_X_WIDGET_INTERNAL_MODE'] as $header) {
            $this->guard($owner)->check($config, Request::create('/', 'POST', [], [], [], [$header => 'true']), 7);
        }

        self::assertSame([403, ['error' => 'Domain not allowed', 'reason' => 'missing_host']], $this->refusal($config, Request::create('/', 'POST', [], [], [], ['HTTP_X_WIDGET_INTERNAL_MODE' => 'true']), $owner, 8));
    }

    private function guard(?User $user = null): WidgetDomainGuard
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new WidgetDomainGuard($security, new NullLogger());
    }

    private function from(string $origin): Request
    {
        return Request::create('/', 'POST', [], [], [], ['HTTP_ORIGIN' => $origin]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function refusal(array $config, Request $request, ?User $user = null, ?int $ownerId = 7): array
    {
        try {
            $this->guard($user)->check($config, $request, $ownerId);
        } catch (WidgetRequestRefused $refused) {
            return [$refused->httpStatus, $refused->payload];
        }

        self::fail('The request was not refused.');
    }
}
