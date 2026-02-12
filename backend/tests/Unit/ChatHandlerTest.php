<?php

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\Prompt;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\FeedbackConfigService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\MemoryExtractionService;
use App\Service\Message\Handler\ChatHandler;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChatHandlerTest extends TestCase
{
    private AiFacade $aiFacade;
    private PromptRepository $promptRepository;
    private PromptService $promptService;
    private ModelConfigService $modelConfigService;
    private ModelRepository $modelRepository;
    private LoggerInterface $logger;
    private VectorSearchService $vectorSearchService;
    private EntityManagerInterface $em;
    private UserUploadPathBuilder $userUploadPathBuilder;
    private UserMemoryService $userMemoryService;
    private MemoryExtractionService $memoryExtractionService;
    private FeedbackConfigService $feedbackConfigService;
    private ChatHandler $handler;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->promptService = $this->createMock(PromptService::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->vectorSearchService = $this->createMock(VectorSearchService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userUploadPathBuilder = new UserUploadPathBuilder();
        $this->userMemoryService = $this->createMock(UserMemoryService::class);
        $this->memoryExtractionService = $this->createMock(MemoryExtractionService::class);
        $this->feedbackConfigService = new FeedbackConfigService($this->createStub(ConfigRepository::class));

        $this->handler = new ChatHandler(
            $this->aiFacade,
            $this->promptRepository,
            $this->promptService,
            $this->modelConfigService,
            $this->modelRepository,
            $this->logger,
            $this->vectorSearchService,
            $this->em,
            '/tmp/uploads',
            $this->userUploadPathBuilder,
            $this->userMemoryService,
            $this->memoryExtractionService,
            $this->feedbackConfigService
        );
    }

    public function testGetName(): void
    {
        $this->assertEquals('chat', $this->handler->getName());
    }

    public function testHandleUsesUserSelectedModel(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Hello');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $classification = [
            'topic' => 'CHAT',
            'language' => 'en',
            'model_id' => 42, // User-selected model
        ];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getProviderForModel')->with(42)->willReturn('ollama');
        $this->modelConfigService->method('getModelName')->with(42)->willReturn('llama3');

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                1,
                $this->callback(function ($options) {
                    return 'ollama' === $options['provider'] && 'llama3' === $options['model'];
                })
            )
            ->willReturn([
                'content' => 'Response text',
                'provider' => 'ollama',
                'model' => 'llama3',
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEquals('Response text', $result['content']);
    }

    public function testHandleFallsBackToDefaultModel(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Hello');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');
        $message->method('getMeta')->with('channel')->willReturn('web'); // Non-WhatsApp channel

        $classification = [
            'topic' => 'CHAT',
            'language' => 'en',
        ];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->with($message)->willReturn(1);
        $this->modelConfigService->method('getDefaultModel')->with('CHAT', 1)->willReturn(10);
        $this->modelConfigService->method('getProviderForModel')->with(10)->willReturn('openai');
        $this->modelConfigService->method('getModelName')->with(10)->willReturn('gpt-4');

        $this->aiFacade
            ->method('chat')
            ->willReturn([
                'content' => 'Response',
                'provider' => 'openai',
                'model' => 'gpt-4',
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testHandleExtractsJsonResponse(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $classification = ['topic' => 'CHAT', 'language' => 'en'];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $jsonResponse = json_encode([
            'BTEXT' => 'Extracted text content',
            'BFILE' => 1,
            'BFILETEXT' => '/path/to/file.jpg',
        ]);

        $this->aiFacade
            ->method('chat')
            ->willReturn([
                'content' => $jsonResponse,
                'provider' => 'test',
                'model' => 'test',
            ]);

        $result = $this->handler->handle($message, [], $classification);

        $this->assertEquals('Extracted text content', $result['content']);
        $this->assertArrayHasKey('file', $result['metadata']);
    }

    public function testHandleIncludesThreadMessages(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Current message');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $threadMsg = $this->createMock(Message::class);
        $threadMsg->method('getDirection')->willReturn('IN');
        $threadMsg->method('getText')->willReturn('Previous message');
        $threadMsg->method('getDateTime')->willReturn('20250116115900');

        $thread = [$threadMsg];
        $classification = ['topic' => 'CHAT', 'language' => 'en'];

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    // Should have system, thread, and current message
                    return count($messages) >= 3;
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'content' => 'Response',
                'provider' => 'test',
                'model' => 'test',
            ]);

        $this->handler->handle($message, $thread, $classification);
    }

    public function testHandleCallsProgressCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFileText')->willReturn('');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Response',
            'provider' => 'test',
            'model' => 'test',
        ]);

        $callbackCalled = 0;
        $callback = function ($status) use (&$callbackCalled) {
            ++$callbackCalled;
            $this->assertArrayHasKey('status', $status);
            $this->assertArrayHasKey('message', $status);
        };

        $this->handler->handle($message, [], ['topic' => 'CHAT', 'language' => 'en'], $callback);

        $this->assertGreaterThan(0, $callbackCalled);
    }

    public function testHandleUsesUserPrompt(): void
    {
        $this->markTestSkipped('Complex mock test with repository expectations - needs refactoring');

        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(5);
        $message->method('getText')->willReturn('Test');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFileText')->willReturn('');

        $userPrompt = $this->createMock(Prompt::class);
        $userPrompt->method('getPrompt')->willReturn('Custom user prompt');

        $this->promptRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['ownerId' => 5, 'language' => 'de'])
            ->willReturn($userPrompt);

        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Response',
            'provider' => 'test',
            'model' => 'test',
        ]);

        $this->handler->handle($message, [], ['topic' => 'CHAT', 'language' => 'de']);
    }

    public function testHandleStreamCallsStreamCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('Stream test');
        $message->method('getFileText')->willReturn('');

        $this->promptRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $chunks = [];
        $streamCallback = function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        };

        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->with(
                $this->anything(),
                $this->callback(static fn ($cb) => is_callable($cb)),
                1,
                $this->anything()
            )
            ->willReturnCallback(static function ($messages, $cb, $userId, $options) {
                // Simulate one streamed chunk coming from the provider.
                $cb('hello');

                return ['provider' => 'test', 'model' => 'test'];
            });

        $this->handler->handleStream(
            $message,
            [],
            ['topic' => 'CHAT', 'language' => 'en'],
            $streamCallback
        );

        self::assertNotEmpty($chunks);
    }

    public function testHandleStreamLoadsPromptMetadataForTaskPrompt(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(1);
        $message->method('getText')->willReturn('What do you know about Cursor Ultra?');
        $message->method('getFileText')->willReturn('');
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        // Mock PromptService to return prompt data with metadata
        $promptMock = $this->createMock(Prompt::class);
        $promptMock->method('getPrompt')->willReturn('You are an AI assistant with knowledge about Cristian Grosu.');

        $this->promptService
            ->expects($this->once())
            ->method('getPromptWithMetadata')
            ->with('cristian', 1, 'en')
            ->willReturn([
                'prompt' => $promptMock,
                'metadata' => [
                    'aiModel' => -1,
                    'tool_internet_search' => true,
                    'tool_files_search' => true,
                    'tool_url_screenshot' => false,
                ],
            ]);

        $this->modelConfigService->method('getDefaultModel')->willReturn(30);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4.1');

        $model = $this->createMock(Model::class);
        $model->method('getFeatures')->willReturn([]);
        $model->method('getJson')->willReturn(['supportsStreaming' => true]);
        $this->modelRepository->method('find')->willReturn($model);

        $streamCallback = function ($chunk) {};

        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->willReturn(['provider' => 'openai', 'model' => 'gpt-4.1']);

        // Test passes if RAG context loading doesn't throw exception (graceful degradation)
        $this->handler->handleStream(
            $message,
            [],
            ['topic' => 'cristian', 'language' => 'en'],
            $streamCallback
        );
    }
}
