<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\MessageController;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\BillingService;
use App\Service\File\DataUrlFixer;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\File\VectorizationService;
use App\Service\Message\AgainHandler;
use App\Service\Message\MessageApiFormatter;
use App\Service\MessageEnqueueService;
use App\Service\ModelConfigService;
use App\Service\PremiumFeatureGate;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\Usage\TranscriptionUsageRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Microphone dictation reuses `/upload-file` only as STT transport (issue #1909).
 * The recording must not remain as a Source and must not consume FILE_ANALYSIS.
 */
final class MessageControllerDictationUploadTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RateLimitService&MockObject $rateLimits;
    private FileStorageService&MockObject $storage;
    private FileProcessor&MockObject $processor;
    private MessageController $controller;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimits = $this->createMock(RateLimitService::class);
        $this->storage = $this->createMock(FileStorageService::class);
        $this->processor = $this->createMock(FileProcessor::class);

        $this->controller = new MessageController(
            $this->em,
            $this->createMock(AiFacade::class),
            $this->createMock(AgainHandler::class),
            $this->createMock(PromptService::class),
            $this->createMock(ModelConfigService::class),
            $this->createMock(MessageEnqueueService::class),
            $this->rateLimits,
            $this->storage,
            $this->processor,
            $this->createMock(VectorizationService::class),
            $this->createMock(MessageRepository::class),
            new MessageApiFormatter(
                $this->createMock(MessageRepository::class),
                $this->createMock(SearchResultRepository::class),
                new DataUrlFixer(
                    $this->em,
                    new UserUploadPathBuilder(),
                    '/tmp',
                    new NullLogger(),
                ),
                $this->createMock(Security::class),
            ),
            new PremiumFeatureGate(new BillingService('', '')),
            new NullLogger(),
        );
        $this->controller->setContainer(new Container());
    }

    public function testDictationOmitsFileIdAndDeletesTheBlob(): void
    {
        $user = $this->makeUser(7);
        $this->rateLimits->expects(self::once())
            ->method('checkLimit')
            ->with($user, TranscriptionUsageRecorder::ACTION)
            ->willReturn(['allowed' => true, 'used' => 0, 'limit' => 100]);

        $this->storage->method('storeUploadedFile')->willReturn([
            'success' => true,
            'path' => 'uploads/7/recording.webm',
            'size' => 21,
            'mime' => 'audio/webm',
            'extension' => 'webm',
            'display_name' => 'recording.webm',
            'error' => null,
        ]);
        $this->storage->expects(self::once())->method('deleteFile')->with('uploads/7/recording.webm');
        $this->processor->method('extractText')->willReturn(['hello from dictation', ['strategy' => 'plain']]);
        $this->em->method('contains')->willReturn(true);
        $this->em->expects(self::once())->method('remove');

        $response = $this->controller->uploadFileForChat($this->dictationRequest(), $user);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayNotHasKey('file_id', $body);
        self::assertSame('hello from dictation', $body['text']);
    }

    public function testChatAttachmentStillGatesOnFileAnalysisAndKeepsTheFile(): void
    {
        $user = $this->makeUser(7);
        $this->rateLimits->expects(self::once())
            ->method('checkLimit')
            ->with($user, 'FILE_ANALYSIS')
            ->willReturn(['allowed' => true, 'used' => 0, 'limit' => 100]);

        $this->storage->method('storeUploadedFile')->willReturn([
            'success' => true,
            'path' => 'uploads/7/notes.txt',
            'size' => 5,
            'mime' => 'text/plain',
            'extension' => 'txt',
            'display_name' => 'notes.txt',
            'error' => null,
        ]);
        $this->storage->expects(self::never())->method('deleteFile');
        $this->processor->method('extractText')->willReturn(['notes', ['strategy' => 'plain']]);
        $this->em->expects(self::never())->method('remove');

        $tmp = tempnam(sys_get_temp_dir(), 'att');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, 'notes');
        $request = new Request([], [], [], [], [
            'file' => new UploadedFile($tmp, 'notes.txt', 'text/plain', null, true),
        ]);

        $response = $this->controller->uploadFileForChat($request, $user);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('file_id', $body);
    }

    private function dictationRequest(): Request
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dict');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "hello from dictation\n");

        return new Request(['purpose' => 'dictation'], ['purpose' => 'dictation'], [], [], [
            'file' => new UploadedFile($tmp, 'recording.webm', 'audio/webm', null, true),
        ]);
    }

    private function makeUser(int $id): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isAdmin')->willReturn(false);

        return $user;
    }
}
