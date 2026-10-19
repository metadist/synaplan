<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ApiKey;
use App\Security\FreeApiGatewayGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class FreeApiGatewayGuardTest extends TestCase
{
    /**
     * @param list<string> $scopes
     */
    private function request(string $path, ?array $scopes, bool $gated = false, string $method = 'POST'): Request
    {
        $request = Request::create($path, $method);
        if (null !== $scopes) {
            $request->attributes->set('api_key', (new ApiKey())
                ->setOwnerId(7)
                ->setKey('sk_test')
                ->setStatus('active')
                ->setName('test')
                ->setScopes($scopes));
        }
        if ($gated) {
            $request->attributes->set(FreeApiGatewayGuard::GATED_ATTRIBUTE, true);
        }

        return $request;
    }

    private function dispatch(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
        (new FreeApiGatewayGuard(new NullLogger()))->onKernelRequest($event);

        return $event;
    }

    #[DataProvider('gatewayPaths')]
    public function testUngatedFreeApiKeyIsRefusedFailClosed(string $path, string $method): void
    {
        $event = $this->dispatch($this->request($path, ['freeapi'], false, $method));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('freeapi_paused', $body['error']['code']);
        self::assertSame('server_error', $body['error']['type']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function gatewayPaths(): iterable
    {
        yield 'chat' => ['/v1/chat/completions', 'POST'];
        yield 'embeddings' => ['/v1/embeddings', 'POST'];
        yield 'images' => ['/v1/images/generations', 'POST'];
        yield 'models' => ['/v1/models', 'GET'];
    }

    public function testGatedFreeApiKeyPasses(): void
    {
        $event = $this->dispatch($this->request('/v1/chat/completions', ['freeapi'], true));

        self::assertNull($event->getResponse());
    }

    public function testMixedFreeApiListIsRefusedEvenWhenMarked(): void
    {
        $event = $this->dispatch($this->request('/v1/chat/completions', ['freeapi', 'desktop:messages'], true));

        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    public function testDemoRoutesAreLeftToThePlugin(): void
    {
        self::assertNull($this->dispatch($this->request('/v1/free/status', ['freeapi'], false, 'GET'))->getResponse());
        self::assertNull($this->dispatch($this->request('/v1/free/keys/self', ['freeapi'], false, 'DELETE'))->getResponse());
    }

    public function testOtherKeysAndSessionsAreUntouched(): void
    {
        self::assertNull($this->dispatch($this->request('/v1/chat/completions', ['desktop:messages']))->getResponse());
        self::assertNull($this->dispatch($this->request('/v1/chat/completions', []))->getResponse());
        self::assertNull($this->dispatch($this->request('/v1/chat/completions', ['*']))->getResponse());
        self::assertNull($this->dispatch($this->request('/v1/chat/completions', ['freeapi', '*']))->getResponse());
        self::assertNull($this->dispatch($this->request('/v1/chat/completions', null))->getResponse());
        self::assertNull($this->dispatch($this->request('/api/v1/messages', ['freeapi']))->getResponse());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $event = $this->dispatch($this->request('/v1/chat/completions', ['freeapi']), HttpKernelInterface::SUB_REQUEST);

        self::assertNull($event->getResponse());
    }

    public function testIsGatedNeedsBothTheMarkAndAnExactFreeApiKey(): void
    {
        self::assertTrue(FreeApiGatewayGuard::isGated($this->request('/v1/chat/completions', ['freeapi'], true)));
        self::assertFalse(FreeApiGatewayGuard::isGated($this->request('/v1/chat/completions', ['freeapi'], false)));
        self::assertFalse(FreeApiGatewayGuard::isGated($this->request('/v1/chat/completions', ['desktop:messages'], true)));
        self::assertFalse(FreeApiGatewayGuard::isGated($this->request('/v1/chat/completions', [], true)));
        self::assertFalse(FreeApiGatewayGuard::isGated($this->request('/v1/chat/completions', null, true)));

        $truthy = $this->request('/v1/chat/completions', ['freeapi']);
        $truthy->attributes->set(FreeApiGatewayGuard::GATED_ATTRIBUTE, '1');
        self::assertFalse(FreeApiGatewayGuard::isGated($truthy));
    }
}
