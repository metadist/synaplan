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
    /** @var array<string, string|null> */
    private array $previous = [];

    /** @var list<string> */
    private array $previousProxies;

    private int $previousHeaderSet;

    protected function setUp(): void
    {
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaderSet = Request::getTrustedHeaderSet();
        foreach ([SignupEdge::ENV_REQUIRE, SignupEdge::ENV_HOPS] as $name) {
            $this->previous[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaderSet);
        foreach ($this->previous as $name => $value) {
            if (null === $value) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
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
        $_ENV[SignupEdge::ENV_HOPS] = '167.235.106.98/32';
        Request::setTrustedProxies(['10.0.0.8'], Request::HEADER_X_FORWARDED_FOR);

        $this->expectException(SignupBlockedException::class);
        $this->expectExceptionMessage('public website');

        SignupEdge::assertRequest($this->direct());
    }

    public function testTheCurrentRequestIsCheckedOnlyWhileHandlingOne(): void
    {
        $_ENV[SignupEdge::ENV_REQUIRE] = 'true';

        (new SignupEdge(new RequestStack()))->assertCurrent();

        $this->addToAssertionCount(1);
    }

    public function testACloudflareHopInFrontOfOurLoadBalancerIsAllowed(): void
    {
        $_ENV[SignupEdge::ENV_REQUIRE] = 'true';
        $_ENV[SignupEdge::ENV_HOPS] = '167.235.106.98/32';
        Request::setTrustedProxies(
            ['10.0.0.8', '167.235.106.98/32', '104.16.0.0/13'],
            Request::HEADER_X_FORWARDED_FOR,
        );
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '10.0.0.8']);
        $request->headers->set('X-Forwarded-For', '203.0.113.10, 104.16.1.1, 167.235.106.98');
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
