<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Entity\Message;
use App\Service\File\UserUploadPathBuilder;
use App\Service\GuestSessionService;
use App\Service\MemoryExtractionDispatcher;
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
 * Unit coverage for {@see StreamController::buildAiModelsPayload()}.
 *
 * Issue #603: the SSE `complete` event must mirror the nested
 * `aiModels` shape returned by the history endpoint so chat / sorting /
 * audio badges populate live instead of only after a page refresh.
 *
 * The helper itself just reads from message meta — the real fix lives
 * in {@see \App\Service\Message\SynapseRouter} (which now writes the
 * sorting model under the canonical `sorting_*` keys) and in the
 * controller error path (which now also persists `ai_sorting_*`). This
 * test pins the contract so future refactors do not silently regress
 * the badge payload.
 */
class StreamControllerAiModelsPayloadTest extends TestCase
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
            $this->createMock(MemoryExtractionDispatcher::class),
        );
    }

    public function testReturnsNullWhenNoModelMetaIsSet(): void
    {
        $message = $this->createPersistedMessage();

        $this->assertNull($this->invokePayload($message));
    }

    public function testBuildsChatOnlyPayloadWhenSortingMetaIsAbsent(): void
    {
        $message = $this->createPersistedMessage();
        $message->setMeta('ai_chat_provider', 'anthropic');
        $message->setMeta('ai_chat_model', 'claude-haiku-4-5');
        $message->setMeta('ai_chat_model_id', '162');

        $payload = $this->invokePayload($message);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('chat', $payload);
        $this->assertSame('anthropic', $payload['chat']['provider']);
        $this->assertSame('claude-haiku-4-5', $payload['chat']['model']);
        $this->assertSame(162, $payload['chat']['model_id']);
        $this->assertArrayNotHasKey('sorting', $payload);
        $this->assertArrayNotHasKey('audio', $payload);
    }

    /**
     * The core regression: once SynapseRouter writes the embedding model
     * under `sorting_*` keys, the StreamController stores them as
     * `ai_sorting_*` meta. This helper must then surface them in the
     * SSE complete event so the Sorting Model badge appears live —
     * exactly what was missing in the live view before fixing #603.
     */
    public function testBuildsCombinedPayloadWithSortingModelMeta(): void
    {
        $message = $this->createPersistedMessage();
        $message->setMeta('ai_chat_provider', 'anthropic');
        $message->setMeta('ai_chat_model', 'claude-haiku-4-5');
        $message->setMeta('ai_chat_model_id', '162');
        $message->setMeta('ai_sorting_provider', 'google');
        $message->setMeta('ai_sorting_model', 'gemini-2.5-pro');
        $message->setMeta('ai_sorting_model_id', '99');

        $payload = $this->invokePayload($message);

        $this->assertIsArray($payload);
        $this->assertSame('google', $payload['sorting']['provider']);
        $this->assertSame('gemini-2.5-pro', $payload['sorting']['model']);
        $this->assertSame(99, $payload['sorting']['model_id']);
    }

    public function testIncludesAudioModelWhenVoiceReplyMetaIsSet(): void
    {
        $message = $this->createPersistedMessage();
        $message->setMeta('ai_chat_provider', 'anthropic');
        $message->setMeta('ai_chat_model', 'claude-haiku-4-5');
        $message->setMeta('ai_audio_provider', 'piper');
        $message->setMeta('ai_audio_model', 'piper-multi');

        $payload = $this->invokePayload($message);

        $this->assertIsArray($payload);
        $this->assertSame('piper', $payload['audio']['provider']);
        $this->assertSame('piper-multi', $payload['audio']['model']);
        $this->assertNull($payload['audio']['model_id']);
    }

    /**
     * Pins the contract that `persistClassificationSortingMeta()` writes
     * the three `ai_sorting_*` meta keys when the classifier handed us a
     * sorting model. Both the streaming and the non-streaming error
     * branches now go through this helper so the live `complete` event
     * (and any later refresh) carries the Sorting Model badge — see
     * issue #603 (refresh-only badges).
     */
    public function testPersistClassificationSortingMetaWritesSortingMetaWhenAvailable(): void
    {
        $message = $this->createPersistedMessage();

        $this->invokePersistSorting($message, [
            'sorting_provider' => 'google',
            'sorting_model_name' => 'gemini-2.5-pro',
            'sorting_model_id' => 99,
            'topic' => 'mediamaker',
        ]);

        $this->assertSame('google', $message->getMeta('ai_sorting_provider'));
        $this->assertSame('gemini-2.5-pro', $message->getMeta('ai_sorting_model'));
        $this->assertSame('99', $message->getMeta('ai_sorting_model_id'));
    }

    public function testPersistClassificationSortingMetaIsNoOpForNullClassification(): void
    {
        $message = $this->createPersistedMessage();

        $this->invokePersistSorting($message, null);

        $this->assertNull($message->getMeta('ai_sorting_provider'));
        $this->assertNull($message->getMeta('ai_sorting_model'));
        $this->assertNull($message->getMeta('ai_sorting_model_id'));
    }

    /**
     * Rule-based routing returns null sorting fields (no model produced
     * the routing decision). The helper must not coerce empty values
     * into "0" / "" meta — those would render as a phantom Sorting
     * Model badge with an unknown identifier.
     */
    public function testPersistClassificationSortingMetaSkipsEmptySortingFields(): void
    {
        $message = $this->createPersistedMessage();

        $this->invokePersistSorting($message, [
            'sorting_provider' => null,
            'sorting_model_name' => '',
            'sorting_model_id' => 0,
            'topic' => 'general',
        ]);

        $this->assertNull($message->getMeta('ai_sorting_provider'));
        $this->assertNull($message->getMeta('ai_sorting_model'));
        $this->assertNull($message->getMeta('ai_sorting_model_id'));
    }

    /**
     * Build a Message with a non-null id so {@see Message::setMeta()}
     * can back-fill {@see \App\Entity\MessageMeta::$messageId} (which
     * is a non-nullable int column on the join table).
     */
    private function createPersistedMessage(): Message
    {
        $message = new Message();
        $reflection = new \ReflectionProperty(Message::class, 'id');
        $reflection->setValue($message, 1);

        return $message;
    }

    private function invokePayload(Message $message): ?array
    {
        $reflection = new \ReflectionMethod(StreamController::class, 'buildAiModelsPayload');

        return $reflection->invoke($this->controller, $message);
    }

    /**
     * @param array<string, mixed>|null $classification
     */
    private function invokePersistSorting(Message $message, ?array $classification): void
    {
        $reflection = new \ReflectionMethod(StreamController::class, 'persistClassificationSortingMeta');
        $reflection->invoke($this->controller, $message, $classification);
    }
}
