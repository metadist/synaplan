<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\TtsController;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read-aloud stream requires the message language (#2283).
 */
final class TtsControllerLanguageTest extends TestCase
{
    private function controller(AiFacade $aiFacade): TtsController
    {
        $controller = new TtsController($aiFacade, new NullLogger());
        // AbstractController::json() reaches into the container.
        $controller->setContainer(new Container());

        return $controller;
    }

    public function testStreamRequiresLanguage(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects(self::never())->method('synthesizeStream');

        $controller = $this->controller($aiFacade);
        $request = Request::create('/api/v1/tts/stream', 'GET', ['text' => 'Hello']);

        $response = $controller->streamAudio($request, $user);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Language is required', $data['error'] ?? null);
    }

    public function testStreamPassesLanguageToFacade(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects(self::once())
            ->method('synthesizeStream')
            ->with('Guten Tag', 'de', 3, self::anything())
            ->willReturn([
                'generator' => (static function (): \Generator {
                    yield 'audio';
                })(),
                'contentType' => 'audio/webm',
                'provider' => 'piper',
                'supportsStreaming' => true,
            ]);

        $controller = $this->controller($aiFacade);
        $request = Request::create('/api/v1/tts/stream', 'GET', [
            'text' => 'Guten Tag',
            'language' => 'de',
        ]);

        $response = $controller->streamAudio($request, $user);

        self::assertSame(200, $response->getStatusCode());
    }
}
