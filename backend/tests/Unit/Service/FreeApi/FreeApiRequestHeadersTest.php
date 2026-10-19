<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\Entity\User;
use App\Service\FreeApi\FreeApiCallContext;
use App\Service\FreeApi\FreeApiRequestHeaders;
use App\Service\FreeApi\FreeApiState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class FreeApiRequestHeadersTest extends TestCase
{
    private const INSTALL_ID = '0b5c3e2a-6a47-4b8e-9b1d-3f1f2f3a4b5c';

    public function testOtherEndpointsGetNothing(): void
    {
        $this->assertSame([], $this->headers(userId: 7, path: '/api/v1/messages/stream')->forCall('localai', []));
    }

    public function testTheDemoEndpointGetsTheHashedUserAndTheSurface(): void
    {
        $headers = $this->headers(userId: 7, path: '/api/v1/widget/abc/message')->forCall('synaplan-demo', []);

        $this->assertSame('widget', $headers['X-Synaplan-Surface']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $headers['X-Synaplan-End-User']);
        $this->assertSame($headers, $this->headers(userId: 7, path: '/api/v1/widget/abc/message')->forCall('synaplan-demo', []));
    }

    public function testTheEndUserCannotBeRecomputedFromWhatTheServerSees(): void
    {
        $endUser = $this->headers(userId: 7, path: '/api/v1/messages/stream')->forCall('synaplan-demo', [])['X-Synaplan-End-User'];

        $this->assertNotSame(hash('sha256', self::INSTALL_ID.':7'), $endUser);
        $this->assertNotSame($endUser, $this->headers(userId: 8, path: '/api/v1/messages/stream')->forCall('synaplan-demo', [])['X-Synaplan-End-User']);
        $this->assertNotSame($endUser, $this->headers(userId: 7, path: '/api/v1/messages/stream', secret: 'other-secret')->forCall('synaplan-demo', [])['X-Synaplan-End-User']);
    }

    public function testWithoutARequestTheCallIsATaskWithoutEndUser(): void
    {
        $this->assertSame(['X-Synaplan-Surface' => 'task'], $this->headers(userId: null, path: null)->forCall('synaplan-demo', []));
    }

    public function testResetForgetsThePreviousRequest(): void
    {
        $context = $this->context(7, '/v1/chat/completions');
        $context->reset();

        $this->assertNull($context->userId());
        $this->assertSame('task', $context->surface());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'chat stream' => ['/api/v1/messages/stream', 'chat'];
        yield 'widget admin is still chat' => ['/api/v1/widgets/5/setup-chat', 'chat'];
        yield 'public widget' => ['/api/v1/widget/abc/message', 'widget'];
        yield 'widget realtime' => ['/api/v1/realtime/widget/abc', 'widget'];
        yield 'widget page' => ['/widget/abc', 'widget'];
        yield 'gateway' => ['/v1/chat/completions', 'api'];
        yield 'platform link' => ['/api/v1/platform-links/x/messages', 'platform-link'];
        yield 'saved task webhook' => ['/api/v1/webhooks/saved-tasks/abc', 'task'];
    }

    #[DataProvider('paths')]
    public function testSurfaceFor(string $path, string $surface): void
    {
        $this->assertSame($surface, FreeApiCallContext::surfaceFor($path));
    }

    private function headers(?int $userId, ?string $path, string $secret = 'test-secret'): FreeApiRequestHeaders
    {
        $state = $this->createStub(FreeApiState::class);
        $state->method('existingInstallId')->willReturn(self::INSTALL_ID);

        return new FreeApiRequestHeaders(null === $path ? $this->context(null, null) : $this->context($userId, $path), $state, $secret);
    }

    private function context(?int $userId, ?string $path): FreeApiCallContext
    {
        $user = null;
        if (null !== $userId) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($userId);
        }
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $context = new FreeApiCallContext($security);
        if (null !== $path) {
            $context->capture(new ControllerEvent(
                $this->createStub(HttpKernelInterface::class),
                static fn () => null,
                Request::create($path),
                HttpKernelInterface::MAIN_REQUEST,
            ));
        }

        return $context;
    }
}
