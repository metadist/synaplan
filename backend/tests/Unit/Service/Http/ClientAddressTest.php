<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Http;

use App\EventSubscriber\ClientAddressSubscriber;
use App\Repository\ConfigRepository;
use App\Service\Auth\SignupAnomalyAlert;
use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupGuard;
use App\Service\Auth\SignupPauseSwitch;
use App\Service\Http\ClientAddress;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ClientAddressTest extends TestCase
{
    /** @var list<string> */
    private array $previousProxies;

    private int $previousHeaderSet;

    private ?string $previousPause = null;

    protected function setUp(): void
    {
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaderSet = Request::getTrustedHeaderSet();
        $this->previousPause = $_ENV[SignupPauseSwitch::ENV_VAR] ?? null;
        unset($_ENV[SignupPauseSwitch::ENV_VAR]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaderSet);
        if (null === $this->previousPause) {
            unset($_ENV[SignupPauseSwitch::ENV_VAR]);
        } else {
            $_ENV[SignupPauseSwitch::ENV_VAR] = $this->previousPause;
        }
    }

    public function testTrustedProxyIsolatesOneAddressFromTheNext(): void
    {
        $this->trustProxy();
        $guard = $this->guard(perIp: 2, global: 100);

        $guard->assertAllowed(ClientAddress::resolve($this->forwarded('203.0.113.10')));
        $guard->assertAllowed(ClientAddress::resolve($this->forwarded('203.0.113.10')));
        $guard->assertAllowed(ClientAddress::resolve($this->forwarded('203.0.113.20')));

        try {
            $guard->assertAllowed(ClientAddress::resolve($this->forwarded('203.0.113.10')));
            $this->fail('The third sign-up from address A must be refused while address B still has room');
        } catch (SignupBlockedException $e) {
            $this->assertSame(SignupBlockedException::CODE_RATE_LIMITED, $e->getReasonCode());
        }

        $guard->assertAllowed(ClientAddress::resolve($this->forwarded('203.0.113.20')));
    }

    public function testUntrustedHopIgnoresForwardedHeaders(): void
    {
        $this->trustProxy();
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.9']);
        $request->headers->set('X-Forwarded-For', '203.0.113.10');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10');

        $this->assertSame('198.51.100.9', ClientAddress::resolve($request));
    }

    public function testTrustedCloudflareHeaderWinsOverASpoofedForwardedFor(): void
    {
        $this->trustProxy();
        $request = $this->forwarded('198.51.100.50');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10');

        $this->assertSame('203.0.113.10', ClientAddress::resolve($request));
    }

    public function testACloudflareListIsIgnored(): void
    {
        $this->trustProxy();
        $request = $this->forwarded('203.0.113.10');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10, 203.0.113.11');

        $this->assertSame('203.0.113.10', ClientAddress::resolve($request));
    }

    public function testSubscriberAlignsGetClientIpWithTheResolver(): void
    {
        $this->trustProxy();
        $request = $this->forwarded('198.51.100.50');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10');

        $kernel = $this->createMock(HttpKernelInterface::class);
        (new ClientAddressSubscriber())->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $this->assertSame('203.0.113.10', $request->getClientIp());
        $this->assertSame($request->getClientIp(), ClientAddress::resolve($request));
    }

    private function trustProxy(): void
    {
        Request::setTrustedProxies(['10.0.0.8'], Request::HEADER_X_FORWARDED_FOR);
    }

    private function forwarded(string $client): Request
    {
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '10.0.0.8']);
        $request->headers->set('X-Forwarded-For', $client);

        return $request;
    }

    private function guard(int $perIp, int $global): SignupGuard
    {
        $config = $this->createMock(ConfigRepository::class);
        $alert = $this->createMock(SignupAnomalyAlert::class);
        $ipFactory = new RateLimiterFactory(['id' => 'signup_ip', 'policy' => 'sliding_window', 'limit' => $perIp, 'interval' => '1 hour'], new InMemoryStorage());
        $globalFactory = new RateLimiterFactory(['id' => 'signup_global', 'policy' => 'sliding_window', 'limit' => $global, 'interval' => '10 minutes'], new InMemoryStorage());

        return new SignupGuard($ipFactory, $globalFactory, new SignupPauseSwitch($config), $alert, new NullLogger());
    }
}
