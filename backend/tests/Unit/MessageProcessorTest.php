<?php

namespace App\Tests\Unit;

use App\Service\Message\MessageProcessor;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageClassifier;
use App\Service\Message\InferenceRouter;
use App\Service\ModelConfigService;
use App\Repository\MessageRepository;
use App\Entity\Message;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageProcessorTest extends TestCase
{
    private MessageRepository $messageRepository;
    private MessagePreProcessor $preProcessor;
    private MessageClassifier $classifier;
    private InferenceRouter $router;
    private ModelConfigService $modelConfigService;
    private LoggerInterface $logger;
    private MessageProcessor $processor;

    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->preProcessor = $this->createMock(MessagePreProcessor::class);
        $this->classifier = $this->createMock(MessageClassifier::class);
        $this->router = $this->createMock(InferenceRouter::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->processor = new MessageProcessor(
            $this->messageRepository,
            $this->preProcessor,
            $this->classifier,
            $this->router,
            $this->modelConfigService,
            $this->logger
        );
    }

    public function testProcessCompletesPipeline(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor
            ->expects($this->once())
            ->method('process')
            ->with($message)
            ->willReturn($message);

        $this->messageRepository
            ->expects($this->once())
            ->method('findConversationHistory')
            ->willReturn([]);

        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn([
                'topic' => 'CHAT',
                'language' => 'en',
                'source' => 'ai_sorting'
            ]);

        $this->router
            ->expects($this->once())
            ->method('route')
            ->willReturn([
                'content' => 'Response',
                'metadata' => ['provider' => 'test', 'model' => 'test']
            ]);

        $result = $this->processor->process($message);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('classification', $result);
    }

    public function testProcessCallsStatusCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting'
        ]);
        $this->router->method('route')->willReturn([
            'content' => 'Response',
            'metadata' => ['provider' => 'test', 'model' => 'test']
        ]);

        $statuses = [];
        $callback = function($status) use (&$statuses) {
            $statuses[] = $status['status'];
        };

        $this->processor->process($message, $callback);

        $this->assertContains('started', $statuses);
        $this->assertContains('preprocessing', $statuses);
        $this->assertContains('classifying', $statuses);
        $this->assertContains('classified', $statuses);
        $this->assertContains('generating', $statuses);
        $this->assertContains('complete', $statuses);
    }

    public function testProcessHandlesProviderException(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting'
        ]);

        $exception = new \App\AI\Exception\ProviderException(
            'Model not found',
            'ollama',
            ['install_command' => 'ollama pull llama3']
        );

        $this->router->method('route')->willThrowException($exception);

        $result = $this->processor->process($message);

        $this->assertFalse($result['success']);
        $this->assertEquals('Model not found', $result['error']);
        $this->assertEquals('ollama', $result['provider']);
        $this->assertArrayHasKey('context', $result);
    }

    public function testProcessHandlesGenericException(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);
        $message->method('getId')->willReturn(1);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting'
        ]);

        $this->router->method('route')->willThrowException(new \Exception('Generic error'));

        $result = $this->processor->process($message);

        $this->assertFalse($result['success']);
        $this->assertEquals('Generic error', $result['error']);
    }

    public function testProcessStreamCallsStreamCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting'
        ]);

        $streamCalled = false;
        $streamCallback = function($chunk) use (&$streamCalled) {
            $streamCalled = true;
        };

        $this->router
            ->expects($this->once())
            ->method('routeStream')
            ->with(
                $message,
                $this->anything(),
                $this->anything(),
                $streamCallback,
                $this->anything(),
                $this->anything()
            )
            ->willReturn(['metadata' => ['provider' => 'test', 'model' => 'test']]);

        $result = $this->processor->processStream($message, $streamCallback);

        $this->assertTrue($result['success']);
    }

    public function testProcessStreamPassesOptions(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(123);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->messageRepository->method('findConversationHistory')->willReturn([]);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting'
        ]);

        $options = ['reasoning' => true, 'temperature' => 0.5];

        $this->router
            ->expects($this->once())
            ->method('routeStream')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $options
            )
            ->willReturn(['metadata' => ['provider' => 'test', 'model' => 'test']]);

        $this->processor->processStream($message, function() {}, null, $options);
    }

    public function testProcessLoadsConversationHistory(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getTrackingId')->willReturn(456);
        $message->method('getFile')->willReturn(0);

        $this->preProcessor->method('process')->willReturn($message);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->messageRepository
            ->expects($this->once())
            ->method('findConversationHistory')
            ->with(1, 456, 10)
            ->willReturn([]);

        $this->classifier->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
            'source' => 'ai_sorting'
        ]);

        $this->router->method('route')->willReturn([
            'content' => 'Response',
            'metadata' => ['provider' => 'test', 'model' => 'test']
        ]);

        $this->processor->process($message);
    }
}

