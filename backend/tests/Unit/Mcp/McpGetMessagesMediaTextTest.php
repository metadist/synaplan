<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Mcp\McpServerFactory;
use App\Observability\EventRingStore;
use App\Repository\ChatRepository;
use App\Repository\DesktopDeviceRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptRepository;
use App\Service\ConversationSummaryRefreshDispatcher;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Desktop\DesktopJobResultNotifier;
use App\Service\Desktop\DesktopJobStore;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\Message\MessageProcessor;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\StorageQuotaService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * MCP get_messages must return localized user text, never raw markers or prompts.
 */
final class McpGetMessagesMediaTextTest extends TestCase
{
    public function testGetMessagesHumanizesImageAndDocumentMarkers(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(9);
        $user->method('getLocale')->willReturn('en');

        $chat = new Chat();
        $chat->setUserId(9);
        $chat->setTitle('Media chat');
        $idProp = new \ReflectionProperty(Chat::class, 'id');
        $idProp->setAccessible(true);
        $idProp->setValue($chat, 42);

        $image = new Message();
        $image->setDirection('OUT');
        $image->setText('__IMAGE_GENERATED__');
        $image->setLanguage('en');
        $image->setTopic('PIC');
        $image->setUnixTimestamp(100);
        $imageId = new \ReflectionProperty(Message::class, 'id');
        $imageId->setAccessible(true);
        $imageId->setValue($image, 1);

        $doc = new Message();
        $doc->setDirection('OUT');
        $doc->setText('__FILE_GENERATED__:report.docx');
        $doc->setLanguage('en');
        $doc->setTopic('CHAT');
        $doc->setUnixTimestamp(101);
        $imageId->setValue($doc, 2);

        $chatRepo = $this->createMock(ChatRepository::class);
        $chatRepo->method('find')->willReturn($chat);

        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo->method('findBy')->willReturn([$doc, $image]); // DESC order, reversed in handler

        $factory = new McpServerFactory(
            $this->createStub(VectorSearchService::class),
            $this->createStub(UserMemoryService::class),
            $this->createStub(MessageProcessor::class),
            $this->createStub(VectorizationService::class),
            $this->createStub(FileStorageService::class),
            $this->createStub(StorageQuotaService::class),
            $this->createStub(PromptRepository::class),
            $chatRepo,
            $messageRepo,
            $this->createStub(RateLimitService::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CacheItemPoolInterface::class),
            $this->createStub(ConversationSummaryRefreshDispatcher::class),
            $this->createStub(DesktopAgentConfig::class),
            $this->createStub(DesktopJobStore::class),
            $this->createStub(DesktopDeviceRepository::class),
            $this->createStub(DesktopJobResultNotifier::class),
            new NullLogger(),
            $this->createStub(EventRingStore::class),
            null,
            $this->renderer(),
        );

        $method = new \ReflectionMethod(McpServerFactory::class, 'getMessagesHandler');
        $method->setAccessible(true);
        /** @var \Closure $handler */
        $handler = $method->invoke($factory, $user);
        $result = $handler(42, 50);

        self::assertTrue($result['success']);
        $texts = array_column($result['messages'], 'text');
        foreach ($texts as $text) {
            self::assertStringNotContainsString('__', $text);
            self::assertStringNotContainsString('Generated ', $text);
        }
        self::assertTrue(
            (bool) array_filter($texts, static fn (string $t): bool => str_contains(strtolower($t), 'image') || str_contains(strtolower($t), 'file') || str_contains(strtolower($t), 'report')),
            'expected humanized image/file sentences: '.json_encode($texts),
        );
    }

    private function renderer(): GeneratedMediaTextRenderer
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'image_generated' => 'Here is the image you asked for.',
            'video_generated' => 'Here is the video you asked for.',
            'audio_generated' => 'Here is the audio you asked for.',
            'file_generated' => "I created the file '{filename}' for you.",
            'file_generation_failed' => 'The file could not be created.',
        ], 'en', 'generated_media');

        return new GeneratedMediaTextRenderer($translator);
    }
}
