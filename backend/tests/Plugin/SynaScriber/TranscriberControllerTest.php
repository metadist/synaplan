<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use App\Entity\User;
use Plugin\SynaScriber\Controller\TranscriberController;
use Plugin\SynaScriber\Service\SessionException;
use Plugin\SynaScriber\Service\SessionService;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class TranscriberControllerTest extends PluginTestCase
{
    private string $upload = '';

    private function controller(?SessionService $sessions = null): TranscriberController
    {
        $controller = new TranscriberController($sessions ?? $this->createStub(SessionService::class), $this->settings());
        $controller->setContainer(new Container());

        return $controller;
    }

    private function user(int $id): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function windowRequest(): Request
    {
        $this->upload = (string) tempnam(sys_get_temp_dir(), 'syn-test-');
        file_put_contents($this->upload, 'OggS-audio');
        $file = new UploadedFile($this->upload, 'window.ogg', 'audio/ogg', null, true);

        return Request::create('/audio', 'POST', ['endpointId' => 'ep1', 'seq' => '3', 't0Ms' => '1000', 't1Ms' => '2500', 'cut' => 'pause'], [], ['audio' => $file]);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->upload && is_file($this->upload)) {
            unlink($this->upload);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(JsonResponse $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    public function testOnlyThePluginOwnersKeyMayCall(): void
    {
        $sessions = $this->createMock(SessionService::class);
        $sessions->expects(self::never())->method('bind');
        $controller = $this->controller($sessions);

        self::assertSame(403, $controller->bind(self::REF, Request::create('/'), $this->user(5))->getStatusCode());
        self::assertSame(403, $controller->bind(self::REF, Request::create('/'), null)->getStatusCode());
    }

    public function testAMultipartWindowReachesTheSession(): void
    {
        $sessions = $this->createMock(SessionService::class);
        $controller = $this->controller($sessions);
        $sessions->expects(self::once())->method('window')
            ->with(self::REF, ['endpointId' => 'ep1', 'seq' => 3, 't0Ms' => 1000, 't1Ms' => 2500, 'audio' => 'OggS-audio', 'cut' => 'pause'])
            ->willReturn(['seq' => 3, 'text' => 'Hallo', 'dropped' => null]);

        $response = $controller->audio(self::REF, $this->windowRequest(), $this->user(self::OWNER));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Hallo', self::body($response)['text']);
    }

    public function testSpeechRecognitionDownAsksTheTranscriberToRetry(): void
    {
        $sessions = $this->createStub(SessionService::class);
        $sessions->method('preview')->willThrowException(new SessionException('stt_unreachable', 'The speech-to-text server did not answer.', 502));
        $controller = $this->controller($sessions);

        $response = $controller->preview(self::REF, $this->windowRequest(), $this->user(self::OWNER));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertSame('stt_unreachable', self::body($response)['error']);
    }

    public function testSessionErrorsKeepTheirStatus(): void
    {
        $sessions = $this->createStub(SessionService::class);
        $sessions->method('bind')->willThrowException(new SessionException('wrong_meeting', 'These meeting notes belong to another meeting.', 409));
        $controller = $this->controller($sessions);

        $response = $controller->bind(self::REF, Request::create('/?meetingId=other'), $this->user(self::OWNER));

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($response->headers->get('Retry-After'));
    }

    public function testEventsAreAcceptedWithoutBody(): void
    {
        $sessions = $this->createMock(SessionService::class);
        $sessions->expects(self::once())->method('event')->with(self::REF, ['type' => 'connected', 'at' => 5]);
        $controller = $this->controller($sessions);

        $request = Request::create('/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"type":"connected","at":5}');

        self::assertSame(204, $controller->events(self::REF, $request, $this->user(self::OWNER))->getStatusCode());
    }

    public function testAnEventThatIsNotJsonIsRefused(): void
    {
        $sessions = $this->createMock(SessionService::class);
        $sessions->expects(self::never())->method('event');
        $controller = $this->controller($sessions);

        $request = Request::create('/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{type: connected');

        self::assertSame(422, $controller->events(self::REF, $request, $this->user(self::OWNER))->getStatusCode());
    }
}
