<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\NativeConsentReturnSubscriber;
use App\Tests\Support\ConsentBrowser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class NativeConsentReturnSubscriberTest extends TestCase
{
    private ConsentBrowser $flow;
    private NativeConsentReturnSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->flow = new ConsentBrowser();
        $this->subscriber = new NativeConsentReturnSubscriber($this->flow->state, 'com.synaplan.app');
    }

    public function testTheBrowserHandsAnAppBoundAnswerToTheAppUntouched(): void
    {
        $state = $this->appState('mcp');
        $event = $this->requestEvent('api_mcp_servers_oauth_callback', ['code' => 'abc', 'state' => $state, 'iss' => 'https://mcp.example']);

        $this->subscriber->onRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringStartsWith('com.synaplan.app://oauth/connect?', $response->getTargetUrl());
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
        self::assertSame(['connector' => 'mcp', 'code' => 'abc', 'state' => $state, 'iss' => 'https://mcp.example'], $query);
    }

    /** A refusal at the provider has to reach the app too, or it waits forever. */
    public function testAProviderErrorForAnAppBoundStateAlsoGoesToTheApp(): void
    {
        $event = $this->requestEvent('api_connections_m365_callback', ['error' => 'access_denied', 'state' => $this->appState('m365')]);

        $this->subscriber->onRequest($event);

        self::assertStringContainsString('connector=m365&error=access_denied', (string) $event->getResponse()?->headers->get('Location'));
    }

    public function testABrowserBoundAnswerStaysWithTheController(): void
    {
        $state = $this->flow->generateState('dropbox', ['owner_id' => 42]);
        $event = $this->requestEvent('api_connections_dropbox_callback', ['code' => 'abc', 'state' => $state]);

        $this->subscriber->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAForgedOrMissingStateStaysWithTheController(): void
    {
        foreach ([['state' => 'forged.state'], ['code' => 'abc'], ['state' => '']] as $query) {
            $event = $this->requestEvent('api_connections_m365_callback', $query);
            $this->subscriber->onRequest($event);
            self::assertNull($event->getResponse());
        }
    }

    /** The app's own request must reach the controller, not bounce back to the app. */
    public function testTheAppsOwnRequestReachesTheController(): void
    {
        $event = $this->requestEvent('api_connections_m365_callback', ['code' => 'abc', 'state' => $this->appState('m365')], 'application/json');

        $this->subscriber->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testOtherRoutesAreLeftAlone(): void
    {
        $event = $this->requestEvent('api_connections_m365_start', ['state' => $this->appState('m365')]);

        $this->subscriber->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testTheAppGetsTheResultAsAPathForItsRouter(): void
    {
        $event = $this->responseEvent(
            'api_connections_m365_callback',
            'application/json',
            new RedirectResponse('https://web.example.com/channels/connections?m365=error&reason=state'),
        );

        $this->subscriber->onResponse($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['redirect' => '/channels/connections?m365=error&reason=state'], json_decode((string) $response->getContent(), true));
    }

    public function testABrowserKeepsItsRedirect(): void
    {
        $redirect = new RedirectResponse('https://web.example.com/channels/connections?m365=connected');
        $event = $this->responseEvent('api_connections_m365_callback', 'text/html,application/xhtml+xml,*/*;q=0.8', $redirect);

        $this->subscriber->onResponse($event);

        self::assertSame($redirect, $event->getResponse());
    }

    public function testJsonAnswersOfOtherRoutesAreLeftAlone(): void
    {
        $redirect = new RedirectResponse('https://web.example.com/somewhere');
        $event = $this->responseEvent('api_other', 'application/json', $redirect);

        $this->subscriber->onResponse($event);

        self::assertSame($redirect, $event->getResponse());
    }

    private function appState(string $provider): string
    {
        $app = new ConsentBrowser();
        $app->startInApp();

        return $app->generateState($provider, ['owner_id' => 42]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(string $route, array $query, string $accept): Request
    {
        $request = new Request($query);
        $request->attributes->set('_route', $route);
        $request->headers->set('Accept', $accept);

        return $request;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function requestEvent(string $route, array $query, string $accept = 'text/html,application/xhtml+xml,*/*;q=0.8'): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $this->request($route, $query, $accept),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function responseEvent(string $route, string $accept, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $this->request($route, [], $accept),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }
}
