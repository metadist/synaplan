<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolCallParser;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Digest\DigestSearchService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\FeedbackConfigService;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageCatalog;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\GeneratedImageVisionFlag;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\Routing\RoutingToolset;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use App\Service\Vision\VisionModelResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Slash `/docs` must tell the model to name the matching source file.
 */
final class ChatHandlerSlashDocsCitationTest extends TestCase
{
    private const USER_ID = 9;

    public function testSlashDocsPathInstructsTheModelToNameTheSourceFile(): void
    {
        $capturedMessages = null;
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return ['content' => 'See invoice-march.pdf', 'provider' => 'test', 'model' => 'test'];
            });

        $vectorSearch = $this->createMock(VectorSearchService::class);
        $vectorSearch->expects($this->once())->method('semanticSearch')->willReturn([
            [
                'id' => 1,
                'file_name' => 'invoice-march.pdf',
                'chunk_text' => 'Invoice total 1200 EUR for March.',
                'score' => 0.91,
            ],
        ]);

        $handler = $this->handler($aiFacade, $vectorSearch);

        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(self::USER_ID);
        $message->method('getId')->willReturn(1001);
        $message->method('getChatId')->willReturn(44);
        $message->method('getText')->willReturn('invoice March');
        $message->method('getFileText')->willReturn('');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn(date('YmdHis'));

        $handler->handle($message, [], [
            'topic' => 'general',
            'language' => 'en',
            'intent' => 'rag_query',
            'slash_docs' => true,
            'skip_sorting' => true,
            'source' => 'tool_command',
        ]);

        self::assertNotNull($capturedMessages);
        $systemPrompt = '';
        foreach ($capturedMessages as $row) {
            if (($row['role'] ?? '') === 'system' && is_string($row['content'] ?? null)) {
                $systemPrompt .= $row['content'];
            }
        }

        self::assertStringContainsString('invoice-march.pdf', $systemPrompt);
        self::assertStringContainsString(
            'name the source file you used from the knowledge context above',
            $systemPrompt,
        );
    }

    public function testOrdinaryRagQueryDoesNotAddTheSlashDocsCitationInstruction(): void
    {
        $capturedMessages = null;
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return ['content' => 'ok', 'provider' => 'test', 'model' => 'test'];
            });

        $vectorSearch = $this->createMock(VectorSearchService::class);
        $vectorSearch->method('semanticSearch')->willReturn([
            [
                'id' => 2,
                'file_name' => 'notes.pdf',
                'chunk_text' => 'Some notes.',
                'score' => 0.8,
            ],
        ]);

        $handler = $this->handler($aiFacade, $vectorSearch);

        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(self::USER_ID);
        $message->method('getId')->willReturn(1002);
        $message->method('getChatId')->willReturn(44);
        $message->method('getText')->willReturn('what is in my notes');
        $message->method('getFileText')->willReturn('');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileType')->willReturn('');
        $message->method('getTopic')->willReturn('CHAT');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getUnixTimestamp')->willReturn(time());
        $message->method('getDateTime')->willReturn(date('YmdHis'));

        $handler->handle($message, [], [
            'topic' => 'general',
            'language' => 'en',
            'intent' => 'rag_query',
            'skip_sorting' => true,
            'source' => 'ai_sorting',
        ]);

        self::assertNotNull($capturedMessages);
        $systemPrompt = (string) ($capturedMessages[0]['content'] ?? '');
        self::assertStringNotContainsString(
            'name the source file you used from the knowledge context above',
            $systemPrompt,
        );
    }

    private function handler(AiFacade $aiFacade, VectorSearchService $vectorSearch): ChatHandler
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('isMemoriesEnabled')->willReturn(false);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->willReturnCallback(
            static fn (mixed $id) => self::USER_ID === $id ? $user : null
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepository);

        $memoryService = $this->createMock(UserMemoryService::class);
        $memoryService->method('isAvailable')->willReturn(false);

        $modelConfigService = $this->createMock(ModelConfigService::class);
        $modelConfigService->method('getDefaultModel')->willReturn(null);
        $modelConfigService->method('resolveUsableModelId')
            ->willReturnCallback(static fn (?int $modelId): ?int => $modelId);

        $promptService = $this->createMock(PromptService::class);
        $promptService->method('getPromptWithMetadata')->willReturn([
            'prompt' => null,
            'metadata' => [],
        ]);

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);

        return new ChatHandler(
            $aiFacade,
            $this->createMock(PromptRepository::class),
            $promptService,
            $modelConfigService,
            $this->createMock(ModelRepository::class),
            new NullLogger(),
            $vectorSearch,
            $em,
            '/tmp/uploads',
            new UserUploadPathBuilder(),
            $memoryService,
            new FeedbackConfigService($configRepository),
            $this->createMock(RateLimitService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(DocumentGeneratorService::class),
            $this->createMock(DocumentImageReferenceResolver::class),
            $this->createMock(DocumentImageCatalog::class),
            new TimeContextBuilder(),
            new KnowledgeContextFormatter(),
            $this->createMock(VisionModelResolver::class),
            $this->createMock(DigestSearchService::class),
            new MessageDigestConfig($configRepository),
            $this->createMock(ConversationFileCatalog::class),
            $this->createMock(GeneratedImageVisionFlag::class),
            $this->createMock(StructuredOutputConfig::class),
            new ToolCallingTranslator(new ToolCallingCapability()),
            new ToolCallParser(),
            new RoutingToolset(new SystemCapabilityRegistry()),
        );
    }
}
