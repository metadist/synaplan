<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupEdge;
use App\Service\Http\ClientAddress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SignupEdgeTest extends TestCase
{
    private ?string $previousRequire = null;

    /** @var list<string> */
    private array $previousProxies;

    private int $previousHeaderSet;

    protected function setUp(): void
    {
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaderSet = Request::getTrustedHeaderSet();
        $this->previousRequire = $_ENV[SignupEdge::ENV_REQUIRE] ?? null;
        unset($_ENV[SignupEdge::ENV_REQUIRE]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaderSet);
        if (null === $this->previousRequire) {
            unset($_ENV[SignupEdge::ENV_REQUIRE]);
        } else {
            $_ENV[SignupEdge::ENV_REQUIRE] = $this->previousRequire;
        }
    }

    public function testThePublicDefaultDoesNotRefuse(): void
    {
        SignupEdge::assertRequest($this->direct());

        $this->addToAssertionCount(1);
    }

    public function testADirectConnectionIsRefusedWhenTheEdgeIsRequired(): void
    {
        $_ENV[SignupEdge::ENV_REQUIRE] = 'true';
        Request::setTrustedProxies(['10.0.0.8'], Request::HEADER_X_FORWARDED_FOR);

        try {
            SignupEdge::assertRequest($this->direct());
            $this->fail('A direct request must be refused when SIGNUP_REQUIRE_CLOUDFLARE=true');
        } catch (SignupBlockedException $e) {
            $this->assertSame(SignupBlockedException::CODE_EDGE_REQUIRED, $e->getReasonCode());
            $this->assertSame(403, $e->getHttpStatus());
            $this->assertStringContainsString('public website', $e->getMessage());
        }
    }

    public function testTheCurrentRequestIsCheckedOnlyWhileHandlingOne(): void
    {
        $_ENV[SignupEdge::ENV_REQUIRE] = 'true';

        (new SignupEdge(new RequestStack()))->assertCurrent();

        $this->addToAssertionCount(1);
    }

    public function testProductionMultiHopChainThroughWorkerPoolEdgeAndLoadBalancerIsAllowed(): void
    {
        $_ENV[SignupEdge::ENV_REQUIRE] = 'true';
        Request::setTrustedProxies(
            ['127.0.0.1', '172.30.10.2', '167.235.106.98/32', '104.16.0.0/13'],
            Request::HEADER_X_FORWARDED_FOR,
        );
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set('X-Forwarded-For', '203.0.113.10, 104.16.1.1, 167.235.106.98, 172.30.10.2');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10');
        ClientAddress::align($request);

        SignupEdge::assertRequest($request);

        $this->assertSame('203.0.113.10', $request->getClientIp());
    }

    public function testASpoofedCloudflareHeaderBehindALocalProxyThatDoesNotTrustCloudflareIsRefused(): void
    {
        $_ENV[SignupEdge::ENV_REQUIRE] = 'true';
        Request::setTrustedProxies(['127.0.0.1', '10.0.0.8'], Request::HEADER_X_FORWARDED_FOR);
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set('X-Forwarded-For', '203.0.113.10, 104.16.1.1, 10.0.0.8');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10');
        ClientAddress::align($request);

        $this->expectException(SignupBlockedException::class);

        SignupEdge::assertRequest($request);
    }

    public function testACloudflarePeerIsAllowedWhenTheEdgeIsRequired(): void
    {
        $_ENV[SignupEdge::ENV_REQUIRE] = 'true';
        Request::setTrustedProxies(['104.16.0.0/13'], Request::HEADER_X_FORWARDED_FOR);
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '104.16.1.1']);
        $request->headers->set('X-Forwarded-For', '203.0.113.10');
        $request->headers->set('CF-Connecting-IP', '203.0.113.10');

        ClientAddress::align($request);
        SignupEdge::assertRequest($request);

        $this->addToAssertionCount(1);
    }

    private function direct(): Request
    {
        return Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.9']);
    }
}
