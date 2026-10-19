<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\FreeApiController;
use App\Entity\User;
use App\Service\FreeApi\FreeApiActivation;
use App\Service\FreeApi\FreeApiActivator;
use App\Service\FreeApi\FreeApiException;
use App\Service\FreeApi\FreeApiLeave;
use App\Service\FreeApi\FreeApiStatusReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class FreeApiControllerTest extends TestCase
{
    public function testStartingWithoutConsentChangesNothing(): void
    {
        $activator = $this->createMock(FreeApiActivator::class);
        $activator->expects(self::never())->method('activate');

        $response = $this->controller($activator)->activate($this->post(['consent' => 'yes']), $this->admin());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(FreeApiController::CONSENT_REQUIRED, $this->body($response)['error']);
    }

    public function testStartingPassesTheAdminAndTheUndoFlag(): void
    {
        $activator = $this->createMock(FreeApiActivator::class);
        $activator->expects(self::once())->method('activate')
            ->with('de', null, true, 7)
            ->willReturn(new FreeApiActivation(1761868800, 14, [], ['CHAT'], false));
        $reader = $this->createMock(FreeApiStatusReader::class);
        $reader->expects(self::once())->method('forget');
        $reader->method('read')->willReturn(['active' => true]);

        $response = $this->controller($activator, $reader)->activate($this->post(['consent' => true, 'force' => true, 'locale' => 'de']), $this->admin());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->body($response)['active']);
    }

    public function testAnInstallWithItsOwnProviderIsAConflict(): void
    {
        $response = $this->failingStart(new FreeApiException('ready', FreeApiException::CHAT_READY));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('freeapi_chat_ready', $this->body($response)['error']);
    }

    public function testABusyDemoServerPassesItsStatusAndRetryAfterOn(): void
    {
        $response = $this->failingStart(new FreeApiException('busy', 'freeapi_throttled', 429, 30));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
    }

    public function testAnUnreachableDemoServerIsABadGateway(): void
    {
        $response = $this->failingStart(FreeApiException::unreachable(new \RuntimeException('timeout')));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('freeapi_unreachable', $this->body($response)['error']);
    }

    public function testLeavingReportsWhatCameBack(): void
    {
        $activator = $this->createStub(FreeApiActivator::class);
        $activator->method('leave')->willReturn(new FreeApiLeave(true, ['CHAT', 'SORT'], false));
        $reader = $this->createStub(FreeApiStatusReader::class);
        $reader->method('read')->willReturn(['active' => false]);

        $body = $this->body($this->controller($activator, $reader)->leave());

        self::assertTrue($body['wasActive']);
        self::assertSame(['CHAT', 'SORT'], $body['restoredCapabilities']);
        self::assertFalse($body['keyRevoked']);
        self::assertFalse($body['status']['active']);
    }

    private function failingStart(FreeApiException $e): JsonResponse
    {
        $activator = $this->createStub(FreeApiActivator::class);
        $activator->method('activate')->willThrowException($e);

        return $this->controller($activator)->activate($this->post(['consent' => true]), $this->admin());
    }

    private function controller(FreeApiActivator $activator, ?FreeApiStatusReader $reader = null): FreeApiController
    {
        $controller = new FreeApiController($activator, $reader ?? $this->createStub(FreeApiStatusReader::class));
        $controller->setContainer(new Container());

        return $controller;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        return new Request(content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function admin(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('isAdmin')->willReturn(true);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(JsonResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
