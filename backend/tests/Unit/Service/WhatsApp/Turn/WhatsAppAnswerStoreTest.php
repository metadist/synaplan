<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\WhatsApp\Turn\WhatsAppAnswerStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class WhatsAppAnswerStoreTest extends TestCase
{
    /** @var list<mixed> */
    private array $steps = [];
    private ?Message $stored = null;

    public function testTheReplyCarriesTheChannelIdsAndItsGeneratedFile(): void
    {
        $message = $this->store(file: ['path' => '/api/v1/files/uploads/7/image.png', 'type' => 'image']);

        self::assertSame(
            ['OUT', 'sent', 1, '/api/v1/files/uploads/7/image.png', 'image', 'mediamaker', 'de', 'Here it is'],
            [$message->getDirection(), $message->getStatus(), $message->getFile(), $message->getFilePath(), $message->getFileType(), $message->getTopic(), $message->getLanguage(), $message->getText()],
        );
        self::assertSame(
            ['whatsapp', '491701234567', '1098765', '+49 30 1234', 'wamid.out'],
            array_map($message->getMeta(...), ['channel', 'to_phone', 'from_phone_number_id', 'from_display_phone', 'external_id']),
        );
    }

    public function testTheModelItsSortingAndItsSourcesBecomeMetadata(): void
    {
        $message = $this->store();

        self::assertSame(
            ['groq', 'gpt-oss', '5', '{"total_tokens":3}', 'openai', 'sorter', '9', 'opening hours', '1'],
            array_map($message->getMeta(...), ['ai_chat_provider', 'ai_chat_model', 'ai_chat_model_id', 'ai_chat_usage', 'ai_sorting_provider', 'ai_sorting_model', 'ai_sorting_model_id', 'web_search_query', 'web_search_results_count']),
        );
        self::assertSame(['SORT', 'transcription'], array_column(json_decode((string) $message->getMeta('ai_usage_extra'), true), 'kind'));
        self::assertSame([0, ''], [$message->getFile(), $message->getFilePath()]);
    }

    public function testTheReplyIsStoredBeforeItsMetadataAndThenTheSummaryRefreshes(): void
    {
        $this->store(chatId: 31);
        self::assertSame([['persist', 'OUT'], ['flush'], ['flush'], ['summary', 31, 7]], $this->steps);

        $this->steps = [];
        $this->store(chatId: null);
        self::assertSame([['persist', 'OUT'], ['flush'], ['flush']], $this->steps);
    }

    /**
     * @param array{path: string, type: string}|null $file
     */
    private function store(?array $file = null, ?int $chatId = 31): Message
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Message) {
                $this->stored = $entity;
                $this->steps[] = ['persist', $entity->getDirection()];
            }
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->steps[] = ['flush'];
        });
        $summaries = $this->createStub(ConversationSummaryRefreshDispatcher::class);
        $summaries->method('dispatch')->willReturnCallback(function (int $chatId, int $userId): void {
            $this->steps[] = ['summary', $chatId, $userId];
        });
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $chat = new Chat();
        if (null !== $chatId) {
            (new \ReflectionProperty(Chat::class, 'id'))->setValue($chat, $chatId);
        }

        (new WhatsAppAnswerStore($em, $summaries))->store(
            $user,
            IncomingMessageDto::fromPayload(
                ['from' => '491701234567', 'id' => 'wamid.in', 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => 'Hello']],
                ['metadata' => ['phone_number_id' => '1098765', 'display_phone_number' => '+49 30 1234']],
            ),
            'Here it is',
            'wamid.out',
            $chat,
            ['query' => 'opening hours', 'results' => [['title' => 'Hours', 'url' => 'https://example.com/hours']]],
            $file,
            ['provider' => 'groq', 'model' => 'gpt-oss', 'model_id' => 5, 'usage' => ['total_tokens' => 3], 'transcription_usage' => ['kind' => 'transcription']],
            ['topic' => 'mediamaker', 'language' => 'de', 'sorting_provider' => 'openai', 'sorting_model_name' => 'sorter', 'sorting_model_id' => 9, 'sorting_usage' => ['tokens' => 4]],
        );

        self::assertNotNull($this->stored);

        return $this->stored;
    }
}
