<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\MessageRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Digest\DigestSearchService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\FeedbackConfigService;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageCatalog;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\Handler\ChatHandler;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE acceptance case of the conversation-continuity plan: "when the user
 * created a document about the office rent 3 months ago, a prompt from that
 * user MUST find the old message and be able to pull it.".
 *
 * Unlike the ChatHandlerTest digest tests (which mock DigestSearchService),
 * this wires the REAL retrieval stack — real DigestSearchService (recency
 * re-rank + stage-2 message pull), real MessageDigestConfig defaults, real
 * KnowledgeContextFormatter — with only the true externals faked: one Qdrant
 * hit for the rent-letter digest, and the 3-month-old BMESSAGES row holding
 * the letter's file text.
 */
class ChatHandlerDigestAcceptanceTest extends TestCase
{
    private const USER_ID = 7;
    private const RENT_MESSAGE_ID = 424242;
    private const RENT_CHAT_ID = 11;
    private const CURRENT_CHAT_ID = 55;

    public function testPromptAboutTheRentPullsTheThreeMonthOldLetter(): void
    {
        $threeMonthsAgo = time() - 90 * 86400;

        // The old message: short chat text + the letter as extracted file text.
        $rentMessage = new Message();
        (new \ReflectionProperty(Message::class, 'id'))->setValue($rentMessage, self::RENT_MESSAGE_ID);
        $rentMessage->setUserId(self::USER_ID);
        $rentMessage->setText('Here is the letter from our landlord about the office rent.');
        $rentMessage->setFileText(
            'Dear tenant, as of September 1st the monthly rent for your office at '
            .'Harbor Street 12 increases from 1450 EUR to 1620 EUR. Meridian Estates GmbH.'
        );

        $qdrantClient = $this->createMock(QdrantClientInterface::class);
        $qdrantClient->method('searchDigests')->willReturn([
            [
                'score' => 0.82,
                'payload' => [
                    'message_id' => self::RENT_MESSAGE_ID,
                    'chat_id' => self::RENT_CHAT_ID,
                    'title' => 'office rent letter to realtor about the increase of payments',
                    'channel' => 'web',
                    'source_date' => $threeMonthsAgo,
                ],
            ],
        ]);

        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->method('find')->willReturnCallback(
            static fn (mixed $id) => self::RENT_MESSAGE_ID === $id ? $rentMessage : null
        );

        // Real config on defaults (no BCONFIG rows).
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);
        $digestConfig = new MessageDigestConfig($configRepository);

        $digestSearchService = new DigestSearchService(
            $qdrantClient,
            $messageRepository,
            $digestConfig,
            new NullLogger(),
        );

        $capturedMessages = null;
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return ['content' => 'ok', 'provider' => 'test', 'model' => 'test'];
            });

        $handler = $this->handler($aiFacade, $digestSearchService, $digestConfig);

        // The new prompt, months later, in a DIFFERENT chat.
        $prompt = $this->createMock(Message::class);
        $prompt->method('getUserId')->willReturn(self::USER_ID);
        $prompt->method('getId')->willReturn(900000);
        $prompt->method('getChatId')->willReturn(self::CURRENT_CHAT_ID);
        $prompt->method('getText')->willReturn('What did our landlord write about the office rent increase?');
        $prompt->method('getFileText')->willReturn('');
        $prompt->method('getFilePath')->willReturn('');
        $prompt->method('getFileType')->willReturn('');
        $prompt->method('getTopic')->willReturn('CHAT');
        $prompt->method('getLanguage')->willReturn('en');
        $prompt->method('getUnixTimestamp')->willReturn(time());
        $prompt->method('getDateTime')->willReturn(date('YmdHis'));

        $result = $handler->handle($prompt, [], ['topic' => 'CHAT', 'language' => 'en']);

        self::assertNotNull($capturedMessages);
        self::assertSame('system', $capturedMessages[0]['role'] ?? '');
        $systemPrompt = $capturedMessages[0]['content'] ?? '';

        // 1. The digest line (reference the model can cite).
        self::assertStringContainsString(
            sprintf('[Msg: %d', self::RENT_MESSAGE_ID),
            $systemPrompt,
            'digest line with the message id must be in the system prompt'
        );
        self::assertStringContainsString('office rent letter to realtor', $systemPrompt);

        // 2. The PULLED verbatim excerpt — the actual letter content, so the
        //    model can quote amounts and dates, not just know the letter exists.
        self::assertStringContainsString('1450 EUR to 1620 EUR', $systemPrompt);
        self::assertStringContainsString('Meridian Estates', $systemPrompt);

        // 3. The reference rules for [Message:ID] badges.
        self::assertStringContainsString('[Message:ID]', $systemPrompt);
        self::assertStringContainsString('Never invent IDs', $systemPrompt);

        // 4. The reference list reaches the caller (for non-streaming channels).
        self::assertSame(self::RENT_MESSAGE_ID, $result['metadata']['digests'][0]['message_id']);
    }

    private function handler(
        AiFacade $aiFacade,
        DigestSearchService $digestSearchService,
        MessageDigestConfig $digestConfig,
    ): ChatHandler {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('isMemoriesEnabled')->willReturn(true);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->willReturnCallback(
            static fn (mixed $id) => self::USER_ID === $id ? $user : null
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepository);

        // The per-turn memory embedding is reused for the digest search.
        $memoryService = $this->createMock(UserMemoryService::class);
        $memoryService->method('isAvailable')->willReturn(true);
        $memoryService->method('embedUserQuery')->willReturn(['embedding' => [0.1, 0.2, 0.3]]);
        $memoryService->method('embedQueryForMemorySearch')->willReturn(['embedding' => [0.1, 0.2, 0.3]]);
        $memoryService->method('getMemoryEmbeddingModelId')->willReturn(10);
        $memoryService->method('searchMemoriesByVector')->willReturn([]);

        $modelConfigService = $this->createMock(ModelConfigService::class);
        $modelConfigService->method('getDefaultModel')->willReturn(null);
        $modelConfigService->method('resolveUsableModelId')
            ->willReturnCallback(static fn (?int $modelId): ?int => $modelId);

        return new ChatHandler(
            $aiFacade,
            $this->createMock(PromptRepository::class),
            $this->createMock(PromptService::class),
            $modelConfigService,
            $this->createMock(ModelRepository::class),
            new NullLogger(),
            $this->createMock(VectorSearchService::class),
            $em,
            '/tmp/uploads',
            new UserUploadPathBuilder(),
            $memoryService,
            new FeedbackConfigService($this->createStub(ConfigRepository::class)),
            $this->createMock(RateLimitService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(DocumentGeneratorService::class),
            $this->createMock(DocumentImageReferenceResolver::class),
            $this->createMock(DocumentImageCatalog::class),
            new TimeContextBuilder(),
            new KnowledgeContextFormatter(),
            $this->createMock(\App\Service\Vision\VisionModelResolver::class),
            $digestSearchService,
            $digestConfig,
            $this->createMock(\App\Service\File\ConversationFileCatalog::class),
            $this->createMock(\App\Service\File\GeneratedImageVisionFlag::class),
        );
    }
}
