<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Entity\Message;
use App\Service\File\UserUploadPathBuilder;
use App\Service\GuestSessionService;
use App\Service\Message\MessageForwardingService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for {@see StreamController::persistOriginalMediaMeta()}.
 *
 * Issue #624: MEDIAMAKER audio responses used to leave the message
 * without `original_topic` / `original_media_type` meta, so the chat
 * badge label flipped from "Chat Model" live to "Audio Model" after a
 * page reload. The helper now persists both keys whenever the
 * classification routes through `mediamaker`, regardless of streaming
 * vs non-streaming code path.
 */
class StreamControllerOriginalMediaMetaTest extends TestCase
{
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->controller = new StreamController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageProcessor::class),
            new NullLogger(),
            $this->createMock(ModelConfigService::class),
            $this->createMock(WidgetService::class),
            $this->createMock(WidgetSessionService::class),
            $this->createMock(GuestSessionService::class),
            $this->createMock(RateLimitService::class),
            '/tmp/upload',
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(PromptService::class),
            $this->createMock(MessageForwardingService::class),
        );
    }

    public function testPersistsOriginalMetaForMediamakerAudio(): void
    {
        $message = $this->createPersistedMessage();

        $this->invokeHelper(
            $message,
            ['topic' => 'mediamaker', 'media_type' => 'audio'],
            [],
        );

        self::assertSame('mediamaker', $message->getMeta('original_topic'));
        self::assertSame('audio', $message->getMeta('original_media_type'));
    }

    public function testPrefersHandlerMetadataMediaTypeOverClassification(): void
    {
        $message = $this->createPersistedMessage();

        // The handler may refine the media type (e.g. user wrote "make a
        // gif" → classifier says `image`, handler picks `video` after
        // reading the prompt). The handler value wins.
        $this->invokeHelper(
            $message,
            ['topic' => 'mediamaker', 'media_type' => 'image'],
            ['media_type' => 'video'],
        );

        self::assertSame('video', $message->getMeta('original_media_type'));
    }

    public function testFallsBackToClassificationMediaTypeWhenHandlerMetadataMissing(): void
    {
        $message = $this->createPersistedMessage();

        $this->invokeHelper(
            $message,
            ['topic' => 'mediamaker', 'media_type' => 'image'],
            [],
        );

        self::assertSame('image', $message->getMeta('original_media_type'));
    }

    public function testNoOpForNonMediamakerClassification(): void
    {
        $message = $this->createPersistedMessage();

        $this->invokeHelper(
            $message,
            ['topic' => 'general', 'media_type' => 'audio'],
            ['media_type' => 'audio'],
        );

        self::assertNull(
            $message->getMeta('original_topic'),
            'Regular chat replies must not leak MEDIAMAKER meta',
        );
        self::assertNull($message->getMeta('original_media_type'));
    }

    public function testMissingTopicIsTreatedAsNonMediamaker(): void
    {
        $message = $this->createPersistedMessage();

        $this->invokeHelper($message, [], []);

        self::assertNull($message->getMeta('original_topic'));
        self::assertNull($message->getMeta('original_media_type'));
    }

    public function testMediamakerWithoutMediaTypePersistsOnlyTopic(): void
    {
        $message = $this->createPersistedMessage();

        // Edge case from production logs: the classifier emits
        // `mediamaker` but the handler hasn't produced media yet
        // (e.g. fallback ask-for-clarification path). We still want
        // `original_topic` so the Again dropdown knows the row was
        // routed through MEDIAMAKER, even without a concrete media kind.
        $this->invokeHelper(
            $message,
            ['topic' => 'mediamaker'],
            [],
        );

        self::assertSame('mediamaker', $message->getMeta('original_topic'));
        self::assertNull($message->getMeta('original_media_type'));
    }

    /**
     * Build a Message with a non-null id so {@see Message::setMeta()} can
     * back-fill {@see \App\Entity\MessageMeta::$messageId} (which is a
     * non-nullable int column on the join table). Without an id, the
     * MessageMeta constructor would throw during meta persistence — see
     * the runtime guard in MessageMeta::setMessage().
     */
    private function createPersistedMessage(): Message
    {
        $message = new Message();
        $reflection = new \ReflectionProperty(Message::class, 'id');
        $reflection->setValue($message, 1);

        return $message;
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     */
    private function invokeHelper(Message $message, array $classification, array $metadata): void
    {
        $reflection = new \ReflectionMethod(StreamController::class, 'persistOriginalMediaMeta');
        $reflection->invoke($this->controller, $message, $classification, $metadata);
    }
}
