<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use Plugin\SynaScriber\Controller\JitsiController;
use Plugin\SynaScriber\Service\SessionService;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class JitsiControllerTest extends PluginTestCase
{
    /**
     * @param array<string, string> $settings
     */
    private function controller(array $settings = []): JitsiController
    {
        $controller = new JitsiController($this->createStub(SessionService::class), $this->settings($settings));
        $controller->setContainer(new Container());

        return $controller;
    }

    public function testTheSilentPageHandsTheCodeToTheConfiguredJitsiOnly(): void
    {
        $response = $this->controller(['jitsi_url' => 'https://meet.example.org/some/room'])->silent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('frame-ancestors https://meet.example.org', $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('var origin = "https://meet.example.org"', $html);
        self::assertStringContainsString("postMessage({ type: 'synascriber-auth', search: window.location.search }, origin)", $html);
        self::assertStringNotContainsString('some/room', $html);
    }

    public function testThePortIsPartOfTheOrigin(): void
    {
        $response = $this->controller(['jitsi_url' => 'https://meet.example.org:8443'])->silent();

        self::assertSame('frame-ancestors https://meet.example.org:8443', $response->headers->get('Content-Security-Policy'));
    }

    public function testThereIsNoPageWithoutAJitsiAddress(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->silent();
    }
}
